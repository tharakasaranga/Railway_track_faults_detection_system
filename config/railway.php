<?php

return [
    'roi_min_overlap' => (float) env('RAILWAY_ROI_MIN_OVERLAP', 0.35),
    'roi_margin_ratio' => (float) env('RAILWAY_ROI_MARGIN_RATIO', 0.15),
];
