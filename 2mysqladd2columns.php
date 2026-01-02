<?php
// Database configuration
$host = 'localhost';
$dbname = 'voedselbank_almere_avg';
$username = 'root';
$password = '';

echo "<h2>Kolommen Toevoegen op Positie 2 en 3</h2>";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Controleer tabel
    $result = $pdo->query("SHOW TABLES LIKE 'avg_register'");
    if ($result->rowCount() == 0) {
        die("❌ Tabel 'avg_register' bestaat niet!");
    }
    
    // Haal eerste kolom op (meestal 'id')
    $columns = $pdo->query("SHOW COLUMNS FROM avg_register");
    $firstCol = $columns->fetch(PDO::FETCH_ASSOC);
    $afterColumn = $firstCol['Field'];
    
    echo "<p>Kolommen worden toegevoegd na: <strong>$afterColumn</strong></p>";
    
    // Kolom 2: avg_registersovereenkomstmetderdepartij
    $check1 = $pdo->query("SHOW COLUMNS FROM avg_register LIKE 'avg_registersovereenkomstmetderdepartij'");
    if ($check1->rowCount() == 0) {
        $sql1 = "ALTER TABLE avg_register 
                 ADD COLUMN avg_registersovereenkomstmetderdepartij ENUM('Ja', 'Nee') DEFAULT 'Nee' 
                 AFTER `$afterColumn`";
        $pdo->exec($sql1);
        echo "✅ <strong>Kolom 2</strong>: 'avg_registersovereenkomstmetderdepartij' toegevoegd<br>";
    } else {
        echo "ℹ️ Kolom 'avg_registersovereenkomstmetderdepartij' bestaat al<br>";
    }
    
    // Kolom 3: wijzijnverwerker (na de net toegevoegde kolom)
    $check2 = $pdo->query("SHOW COLUMNS FROM avg_register LIKE 'wijzijnverwerker'");
    if ($check2->rowCount() == 0) {
        $afterCol2 = 'avg_registersovereenkomstmetderdepartij';
        $sql2 = "ALTER TABLE avg_register 
                 ADD COLUMN wijzijnverwerker ENUM('Ja', 'Nee') DEFAULT 'Nee' 
                 AFTER `$afterCol2`";
        $pdo->exec($sql2);
        echo "✅ <strong>Kolom 3</strong>: 'wijzijnverwerker' toegevoegd<br>";
    } else {
        echo "ℹ️ Kolom 'wijzijnverwerker' bestaat al<br>";
    }
    
    echo "<hr><h3>✅ Database succesvol aangepast!</h3>";
    
    // Toon nieuwe structuur
    echo "<h4>Nieuwe tabel structuur:</h4>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Positie</th><th>Kolom Naam</th><th>Type</th></tr>";
    
    $columns = $pdo->query("SHOW COLUMNS FROM avg_register");
    $pos = 1;
    while ($row = $columns->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$pos}</td>";
        echo "<td><strong>{$row['Field']}</strong></td>";
        echo "<td>{$row['Type']}</td>";
        echo "</tr>";
        $pos++;
    }
    echo "</table>";
    
} catch(PDOException $e) {
    die("❌ Fout: " . $e->getMessage());
}
?>