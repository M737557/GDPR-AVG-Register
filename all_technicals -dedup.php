<?php
// Direct dump - just the measures, one per line with deduplication
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'database_name';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Force plain text output
header('Content-Type: text/plain');

$sql = "SELECT technische_maatregelen FROM avg_register ORDER BY id";
$result = $conn->query($sql);

$all_measures = []; // Array to collect all measures for deduplication
$raw_measures = []; // Keep original order if needed

while($row = $result->fetch_assoc()) {
    $measures = $row['technische_maatregelen'];
    
    // Clean and split into array
    $clean = preg_replace('/\s*[.,;]\s*/', "\n", $measures);
    $clean = preg_replace('/\n+/', "\n", $clean); // Remove multiple newlines
    $clean = trim($clean);
    
    if (!empty($clean)) {
        // Split by newlines to get individual measures
        $measure_lines = explode("\n", $clean);
        
        foreach ($measure_lines as $measure) {
            $trimmed_measure = trim($measure);
            if (!empty($trimmed_measure)) {
                $raw_measures[] = $trimmed_measure;
            }
        }
    }
}

$conn->close();

// DEDUPLICATION OPTIONS - Choose one method:

echo "\n\n=== OPTION 3: Fuzzy Deduplication (Similarity Check) ===\n\n";
// OPTION 3: Fuzzy deduplication (removes similar measures)
$similarity_threshold = 80; // Percentage (0-100)
$fuzzy_unique = [];

foreach ($raw_measures as $measure) {
    $is_similar = false;
    $measure_lower = strtolower($measure);
    
    foreach ($fuzzy_unique as $unique_measure) {
        $unique_lower = strtolower($unique_measure);
        
        // Calculate similarity
        similar_text($measure_lower, $unique_lower, $similarity);
        
        if ($similarity >= $similarity_threshold) {
            $is_similar = true;
            break;
        }
    }
    
    if (!$is_similar) {
        $fuzzy_unique[] = $measure;
    }
}

foreach ($fuzzy_unique as $measure) {
    echo $measure . "\n";
}

echo "\n\n=== STATISTICS ===\n";
echo "Total measures found: " . count($raw_measures) . "\n";

echo "Unique (fuzzy {$similarity_threshold}%): " . count($fuzzy_unique) . "\n";
