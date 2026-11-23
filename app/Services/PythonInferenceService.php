<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class PythonInferenceService
{
    public function process(
        string $inputPath,
        string $outputPath,
        string $modelPath,
        float $confidence = 0.25,
        ?callable $onProgress = null,
        ?callable $shouldCancel = null,
        string $modelType = 'yolov8',
        array $ensembleModelPaths = [],
        ?string $detectionImagesRelativePath = null
    ): array {
        $logger = Log::channel('video_processing');

        $pythonExecutable = $this->resolvePythonExecutable();
        $scriptPath = base_path('python/inference.py');

        if (! is_file($scriptPath)) {
            throw new RuntimeException('Python inference script was not found: '.$scriptPath);
        }

        $logger->info('[PYTHON] PROCESS_START', [
            'python_executable' => $pythonExecutable,
            'script_path' => $scriptPath,
            'input_path' => $inputPath,
            'output_path' => $outputPath,
            'model_path' => $modelPath,
            'model_type' => $modelType,
            'ensemble_model_paths' => $ensembleModelPaths,
            'confidence' => $confidence,
            'roi_min_overlap' => config('railway.roi_min_overlap', 0.35),
            'roi_margin_ratio' => config('railway.roi_margin_ratio', 0.15),
        ]);

        if (! in_array($modelType, ['yolov8', 'mobilenet', 'fasterrcnn', 'ensemble'], true)) {
            throw new RuntimeException('Unsupported model type: '.$modelType);
        }

        if (! is_file($inputPath) || ! is_file($modelPath)) {
            throw new RuntimeException('Python inference input video or model weights are missing.');
        }

        if ($modelType === 'ensemble') {
            foreach (['mobilenet', 'fasterrcnn'] as $requiredModel) {
                if (! isset($ensembleModelPaths[$requiredModel]) || ! is_file($ensembleModelPaths[$requiredModel])) {
                    throw new RuntimeException('Ensemble weights are missing for '.$requiredModel.'.');
                }
            }
        }

        $outputDirectory = dirname($outputPath);
        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException('Unable to create output directory: '.$outputDirectory);
        }

        $detectionsPath = $outputPath.'.detections.jsonl';
        $cancelPath = $outputPath.'.cancel';
        $detectionImagesPath = $detectionImagesRelativePath
            ? Storage::disk('local')->path($detectionImagesRelativePath)
            : null;
        if (is_file($detectionsPath)) {
            unlink($detectionsPath);
        }
        if (is_file($cancelPath)) {
            unlink($cancelPath);
        }
        if ($detectionImagesRelativePath) {
            Storage::disk('local')->deleteDirectory($detectionImagesRelativePath);
            if (! is_dir($detectionImagesPath) && ! mkdir($detectionImagesPath, 0775, true) && ! is_dir($detectionImagesPath)) {
                throw new RuntimeException('Unable to create detection images directory: '.$detectionImagesPath);
            }
        }

        $processArguments = [
            $pythonExecutable,
            $scriptPath,
            '--input', $inputPath,
            '--output', $outputPath,
            '--model', $modelPath,
            '--model-type', $modelType,
            '--confidence', (string) $confidence,
            '--roi-min-overlap', (string) config('railway.roi_min_overlap', 0.35),
            '--roi-margin-ratio', (string) config('railway.roi_margin_ratio', 0.15),
            '--detections-output', $detectionsPath,
            '--cancel-file', $cancelPath,
        ];
        if ($detectionImagesPath) {
            array_push($processArguments, '--detection-images-dir', $detectionImagesPath);
        }
        if ($modelType === 'ensemble') {
            array_push(
                $processArguments,
                '--mobilenet-model', $ensembleModelPaths['mobilenet'],
                '--fasterrcnn-model', $ensembleModelPaths['fasterrcnn'],
            );
        }
        $fastenerModelPath = base_path('python/models/old/best_old.pt');
        if (is_file($fastenerModelPath) && filesize($fastenerModelPath) > 0) {
            array_push($processArguments, '--fastener-model', $fastenerModelPath);
        } else {
            $logger->warning('[PYTHON] Fastener specialist checkpoint is unavailable', [
                'expected_path' => $fastenerModelPath,
            ]);
        }
        $process = new Process($processArguments);

        $process->setTimeout(null);

        $stderrBuffer = '';
        $cancellationRequested = false;
        try {
            $process->run(function (string $type, string $buffer) use (&$stderrBuffer, &$cancellationRequested, $cancelPath, $shouldCancel, $onProgress, $logger): void {
                if ($type !== Process::ERR) {
                    return;
                }

                $stderrBuffer .= $buffer;

                while (($newline = strpos($stderrBuffer, "\n")) !== false) {
                    $line = trim(substr($stderrBuffer, 0, $newline));
                    $stderrBuffer = substr($stderrBuffer, $newline + 1);

                    if ($line === '') {
                        continue;
                    }

                    $payload = json_decode($line, true);
                    if (is_array($payload) && isset($payload['event'])) {
                        if ($shouldCancel && $shouldCancel()) {
                            if (! $cancellationRequested) {
                                file_put_contents($cancelPath, 'cancel');
                                $cancellationRequested = true;
                            }

                            continue;
                        }

                        if ($onProgress) {
                            $onProgress($payload);
                        }
                    } else {
                        $logger->debug('[PYTHON] NON_PROTOCOL_STDERR', ['line' => $line]);
                    }
                }
            });

            if ($cancellationRequested || ($shouldCancel && $shouldCancel())) {
                throw new RuntimeException('Analysis was cancelled.');
            }

            if (trim($stderrBuffer) !== '') {
                $payload = json_decode(trim($stderrBuffer), true);
                if (is_array($payload) && isset($payload['event'])) {
                    if ($onProgress) {
                        $onProgress($payload);
                    }
                }
            }

            $output = trim($process->getOutput());
            $result = json_decode($output, true);

            if (! $process->isSuccessful()) {
                $message = is_array($result)
                    ? ($result['error'] ?? 'Python inference failed.')
                    : trim($process->getErrorOutput() ?: 'Python inference failed.');

                $logger->error('[PYTHON] PROCESS_FAILED', [
                    'exit_code' => $process->getExitCode(),
                    'message' => $message,
                    'stderr' => trim($process->getErrorOutput()),
                ]);

                throw new RuntimeException($message);
            }

            if (! is_array($result)) {
                $details = trim($process->getErrorOutput() ?: '');
                throw new RuntimeException('Python returned invalid JSON.'.($details ? "\n".$details : ''));
            }

            if (($result['success'] ?? false) !== true) {
                throw new RuntimeException($result['error'] ?? 'Unknown Python inference error.');
            }

            if (! is_file($detectionsPath)) {
                throw new RuntimeException('Python did not create its detections output file.');
            }

            $detectionCount = 0;
            $detectionFile = new \SplFileObject($detectionsPath, 'r');
            while (! $detectionFile->eof()) {
                $line = trim((string) $detectionFile->fgets());
                if ($line === '') {
                    continue;
                }

                if ($shouldCancel && $shouldCancel()) {
                    throw new RuntimeException('Analysis was cancelled.');
                }

                $detection = json_decode($line, true);
                if (! is_array($detection)) {
                    throw new RuntimeException('Python wrote an invalid detection record.');
                }
                if ($detectionImagesRelativePath) {
                    if (! isset($detection['image_filename'])) {
                        throw new RuntimeException('Python did not include an image for a detected defect.');
                    }
                    $imageFilename = basename((string) $detection['image_filename']);
                    $imagePath = $detectionImagesPath.DIRECTORY_SEPARATOR.$imageFilename;
                    if ($imageFilename === '' || ! is_file($imagePath)) {
                        throw new RuntimeException('Python did not create an expected detection image.');
                    }
                    $detection['image_path'] = trim($detectionImagesRelativePath, '/\\').'/'.$imageFilename;
                    unset($detection['image_filename']);
                }

                $onProgress && $onProgress(['event' => 'detection', 'detection' => $detection]);
                $detectionCount++;
            }

            $result['detection_count'] = $detectionCount;

            if ($onProgress) {
                $onProgress([
                    'event' => 'progress',
                    'stage' => 'completed',
                    'total_frames' => (int) ($result['total_frames'] ?? 0),
                    'processed_frames' => (int) ($result['processed_frames'] ?? 0),
                    'progress' => 100,
                    'fps' => $result['fps'] ?? 0,
                    'elapsed_seconds' => $result['processing_time'] ?? 0,
                    'eta_seconds' => 0,
                ]);
            }

            return $result;
        } finally {
            if (is_file($detectionsPath)) {
                unlink($detectionsPath);
            }
            if (is_file($cancelPath)) {
                unlink($cancelPath);
            }
        }
    }

    protected function resolvePythonExecutable(): string
    {
        $candidates = [
            base_path('python/.venv/Scripts/python.exe'),
            base_path('python/.venv/bin/python'),
            base_path('python/venv/Scripts/python.exe'),
            base_path('python/venv/bin/python'),
            $this->commandExists('python3') ? 'python3' : '',
            $this->commandExists('python') ? 'python' : '',
            'python',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && (is_file($candidate) || $this->commandExists($candidate))) {
                return $candidate;
            }
        }

        return 'python';
    }

    protected function commandExists(string $command): bool
    {
        $which = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? 'where '.escapeshellarg($command)
            : 'command -v '.escapeshellarg($command);

        $output = shell_exec($which);

        return is_string($output) && trim($output) !== '';
    }
}
