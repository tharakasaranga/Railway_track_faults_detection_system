<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

## Large Video Uploads

The application accepts videos up to 500 MB. PHP-FPM reads the upload limits in `public/.user.ini`; Apache with mod_php reads the matching values in `public/.htaccess`. Restart or reload the PHP service after changing these settings. The web server must also allow request bodies larger than the video, for example `client_max_body_size 620m;` in Nginx or `LimitRequestBody 650117120` in Apache. Ensure PHP's temporary upload directory has enough free disk space.

When using `php artisan serve`, PHP CLI ignores both project-level files. Update `upload_max_filesize`, `post_max_size`, `max_input_time`, and `max_execution_time` in the loaded CLI `php.ini`, then restart the server. On the current Windows setup, that file is `C:\xampp\php\php.ini`.

## Queue Worker

Video analysis uses Laravel's database queue. Keep a worker running alongside the web server to process uploads and update analysis progress:

```sh
php artisan queue:work --queue=default --tries=1 --timeout=0
```

Queued videos remain at 0% until a worker claims them.

## Railway fault models

The upload screen supports YOLOv8, MobileNet-SSDLite, Faster R-CNN, and **Ensemble (All Models)**. The ensemble runs all three checkpoints on every frame and labels/color-codes detections by model. Model files are intentionally excluded from Git by `.gitignore`; place the trained files at:

- `python/models/best/best.pt`
- `python/models/mobileNet/best_mobilenet_ssd.pth`
- `python/models/fasterRCnn/best_model.pth`
- `python/models/old/best_old.pt` (optional fastener specialist)

When the optional fastener checkpoint is present, inference uses its `fasteners` class as a specialist, maps it to `Fastener_Defect`, and uses a 0.15 confidence threshold. Specialist detections are retained when rail-boundary localization is uncertain; inspect their boxes in the annotated video. Other classes continue to use the selected model and configured confidence/ROI filtering. Same-class detections with overlapping boxes or nearby centers are grouped into one event across gaps of up to 30 frames, and the highest-confidence detection represents each event.

After applying migrations, register whichever checkpoint files are present:

```sh
php artisan migrate
php artisan db:seed --class=ModelWeightSeeder
```

Run the standalone evaluation against a COCO-format test dataset (category names should match the four trained defect classes):

```sh
python -m pip install -r python/requirements.txt
python python/evaluate_models.py --images path/to/test/images --annotations path/to/instances_test.json
```

The report includes `mAP @ 0.50`, `mAP @ 0.50:0.95`, and inference FPS for each model. Optional `--yolo-weights`, `--mobilenet-weights`, and `--fasterrcnn-weights` arguments override the default checkpoint paths; `--device cpu` or `--device cuda` selects the inference device.

### Track ROI filtering

Each frame is localized with perspective-aware rail-line detection (CLAHE/Canny edges and paired Hough lines), then the resulting four-point track polygon is temporally smoothed. Detections from the selected model whose bounding boxes overlap the polygon by at least `RAILWAY_ROI_MIN_OVERLAP` are stored or drawn; the default threshold is `0.35`. `RAILWAY_ROI_MARGIN_RATIO` (default `0.15`) expands the polygon beyond the detected rail lines to retain defects near rail edges. Both settings can be changed in `.env`.

If a frame does not produce a sufficiently plausible pair of rail boundaries, no detections from that frame are accepted. The output marks it `TRACK ROI UNCERTAIN - REVIEW FRAME`, and the video summary reports the number of such frames. This fail-closed behavior avoids treating uncertain localization as permission to accept every model prediction. Inspect these frames in the annotated video; camera position, lighting, and track appearance can affect classical rail-line localization.

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
