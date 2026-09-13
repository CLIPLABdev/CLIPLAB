<?php
require 'bootstrap/app.php';

$actual = "((`aspect_ratio` = _utf8mb4'original') and (`reframe_mode` = _utf8mb4'original') and (`output_width` is null) and (`output_height` is null)) or ((`reframe_mode` in (_utf8mb4'center',_utf8mb4'manual',_utf8mb4'auto')) and (`output_width` is not null) and (`output_height` is not null) and (((`aspect_ratio` = _utf8mb4'9:16') and (((`output_width` = 720) and (`output_height` = 1280)) or ((`output_width` = 1080) and (`output_height` = 1920)))) or ((`aspect_ratio` = _utf8mb4'1:1') and (((`output_width` = 720) and (`output_height` = 720)) or ((`output_width` = 1080) and (`output_height` = 1080)))) or ((`aspect_ratio` = _utf8mb4'16:9') and (((`output_width` = 1280) and (`output_height` = 720)) or ((`output_width` = 1920) and (`output_height` = 1080)))) or ((`aspect_ratio` = _utf8mb4'4:5') and (((`output_width` = 720) and (`output_height` = 900)) or ((`output_width` = 1080) and (`output_height` = 1350)))))))";
$expected = "(aspect_ratio = 'original' AND reframe_mode = 'original' AND output_width IS NULL AND output_height IS NULL) OR (reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND ((aspect_ratio = '9:16' AND ((output_width = 720 AND output_height = 1280) OR (output_width = 1080 AND output_height = 1920))) OR (aspect_ratio = '1:1' AND ((output_width = 720 AND output_height = 720) OR (output_width = 1080 AND output_height = 1080))) OR (aspect_ratio = '16:9' AND ((output_width = 1280 AND output_height = 720) OR (output_width = 1920 AND output_height = 1080))) OR (aspect_ratio = '4:5' AND ((output_width = 720 AND output_height = 900) OR (output_width = 1080 AND output_height = 1350)))))";

$method = new ReflectionMethod('App\\Core\\Migrator', 'canonicalCheck');
$actualCanonical = $method->invoke(null, $actual);
$expectedCanonical = $method->invoke(null, $expected);
var_dump($actualCanonical);
var_dump($expectedCanonical);
var_dump(App\Core\Migrator::checkConstraintMetadataMatches($actual, $expected, 'YES'));
