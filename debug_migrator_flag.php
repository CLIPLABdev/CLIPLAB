<?php
require 'bootstrap/app.php';

use App\Core\Database;
use App\Core\Migrator;

$pdo = Database::connection();
$expected = "(aspect_ratio = 'original' AND reframe_mode = 'original' AND output_width IS NULL AND output_height IS NULL) OR (reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND ((aspect_ratio = '9:16' AND ((output_width = 720 AND output_height = 1280) OR (output_width = 1080 AND output_height = 1920))) OR (aspect_ratio = '1:1' AND ((output_width = 720 AND output_height = 720) OR (output_width = 1080 AND output_height = 1080))) OR (aspect_ratio = '16:9' AND ((output_width = 1280 AND output_height = 720) OR (output_width = 1920 AND output_height = 1080))) OR (aspect_ratio = '4:5' AND ((output_width = 720 AND output_height = 900) OR (output_width = 1080 AND output_height = 1350)))))";
$sql = "SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, tc.ENFORCED FROM information_schema.TABLE_CONSTRAINTS tc INNER JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'clip_render_profiles' AND tc.CONSTRAINT_TYPE = 'CHECK' ORDER BY tc.CONSTRAINT_NAME";
$normalize = \Closure::bind(static fn(string $value): ?string => Migrator::normalizeShapeClause($value), null, Migrator::class);
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (str_contains((string) $row['CONSTRAINT_NAME'], 'shape')) {
        $actual = (string) $row['CHECK_CLAUSE'];
        echo "ACTUAL=$actual\n";
        echo "MATCH=" . (Migrator::checkConstraintMetadataMatches($actual, $expected, (string) ($row['ENFORCED'] ?? 'YES')) ? 'true' : 'false') . "\n";
        $actualNorm = $normalize($actual);
        $expectedNorm = $normalize($expected);
        echo "ACTUAL_NORM=$actualNorm\n";
        echo "EXPECTED_NORM=$expectedNorm\n";
    }
}
