<?php
// Database configuratie
$host = 'localhost';
$db   = 'voedselbank_almere_avg';
$user = 'root';  // Pas aan naar jouw database gebruiker
$pass = '';      // Pas aan naar jouw wachtwoord
$charset = 'utf8mb4';

// PDO connectie
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connectie mislukt: " . $e->getMessage());
}

// Handelingen verwerken
$message = '';
$deleted_ids = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_selected']) && !empty($_POST['delete_ids'])) {
        // Verwijder geselecteerde records permanent
        $ids = explode(',', $_POST['delete_ids']);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        $stmt = $pdo->prepare("DELETE FROM avg_register WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $affected = $stmt->rowCount();
        $message = "⚠️ $affected record(s) permanent verwijderd!";
        $deleted_ids = $ids;
    }
}

// Haal statistieken op
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN created_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR) 
                 AND updated_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR) 
                 THEN 1 ELSE 0 END) as older_than_2_years_both,
        SUM(CASE WHEN created_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR) 
                 THEN 1 ELSE 0 END) as created_older_than_2_years,
        SUM(CASE WHEN updated_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR) 
                 THEN 1 ELSE 0 END) as updated_older_than_2_years
    FROM avg_register
")->fetch();

// Haal records op die ouder zijn dan 2 jaar in BEIDE velden
$oldRecords = $pdo->query("
    SELECT *, 
           DATEDIFF(NOW(), created_at) as created_dagen_oud,
           DATEDIFF(NOW(), updated_at) as updated_dagen_oud,
           FLOOR(DATEDIFF(NOW(), created_at) / 365) as created_jaren_oud,
           FLOOR(DATEDIFF(NOW(), updated_at) / 365) as updated_jaren_oud,
           CASE 
               WHEN created_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR) 
               AND updated_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR) THEN 'beide_oud'
               WHEN created_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR) THEN 'created_oud'
               WHEN updated_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR) THEN 'updated_oud'
               ELSE 'beide_nieuw'
           END as leeftijd_status
    FROM avg_register 
    WHERE created_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR)
       OR updated_at <= DATE_SUB(NOW(), INTERVAL 2 YEAR)
    ORDER BY created_at ASC
")->fetchAll();

// Categoriseer de records
$categorizedRecords = [
    'beide_oud' => [],
    'created_oud' => [],
    'updated_oud' => [],
    'beide_nieuw' => []
];

foreach ($oldRecords as $record) {
    $categorizedRecords[$record['leeftijd_status']][] = $record;
}

// Haal alle records op voor overzicht
$allRecords = $pdo->query("
    SELECT id, verwerkingsactiviteit, created_at, updated_at, risiconiveau,
           DATEDIFF(NOW(), created_at) as created_dagen,
           DATEDIFF(NOW(), updated_at) as updated_dagen
    FROM avg_register 
    ORDER BY created_at ASC
    LIMIT 30
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AVG Register Beheer - Dual Date Check</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: white; border-radius: 10px; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 20px; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; margin: 20px 0 10px 0; font-size: 1.3em; }
        h3 { color: #7f8c8d; margin: 15px 0 5px 0; font-size: 1.1em; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-card { padding: 15px; border-radius: 5px; text-align: center; }
        .stat-number { font-size: 2em; font-weight: bold; }
        .stat-label { font-size: 0.9em; margin-top: 5px; }
        .beide-oud { background: #ffeaa7; border: 1px solid #fdcb6e; }
        .created-oud { background: #dfe6e9; border: 1px solid #b2bec3; }
        .updated-oud { background: #d8f5e3; border: 1px solid #81ecec; }
        .beide-nieuw { background: #dff9fb; border: 1px solid #c7ecee; }
        .message { padding: 15px; margin: 20px 0; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .record-item { 
            background: white; 
            padding: 12px; 
            margin: 8px 0; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .record-item:hover { background: #f8f9fa; transform: translateX(5px); }
        .record-item.selected { background: #fff3cd; }
        .record-id { font-weight: bold; color: #2c3e50; }
        .record-age { float: right; padding: 2px 8px; border-radius: 12px; font-size: 0.85em; }
        .created-age { background: #dfe6e9; color: #2d3436; }
        .updated-age { background: #d8f5e3; color: #00b894; margin-left: 5px; }
        .record-activity { display: block; margin-top: 5px; color: #555; font-size: 0.9em; }
        .date-indicator { 
            position: absolute; 
            top: 0; 
            left: 0; 
            width: 4px; 
            height: 100%; 
            border-radius: 5px 0 0 5px;
        }
        .beide-oud-indicator { background: linear-gradient(to bottom, #e74c3c 50%, #e74c3c 50%); }
        .created-oud-indicator { background: linear-gradient(to bottom, #e74c3c 50%, #3498db 50%); }
        .updated-oud-indicator { background: linear-gradient(to bottom, #3498db 50%, #e74c3c 50%); }
        .beide-nieuw-indicator { background: linear-gradient(to bottom, #2ecc71 50%, #2ecc71 50%); }
        .btn { 
            padding: 10px 20px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
            font-size: 14px; 
            margin: 5px; 
            transition: all 0.3s;
        }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-danger:disabled { background: #95a5a6; cursor: not-allowed; }
        .btn-info { background: #3498db; color: white; }
        .btn-info:hover { background: #2980b9; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #d68910; }
        .selection-info { 
            background: #e3f2fd; 
            padding: 10px; 
            margin: 10px 0; 
            border-radius: 5px; 
            font-size: 0.9em; 
        }
        .form-container { margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 5px; }
        .hidden-input { display: none; }
        .empty-state { text-align: center; padding: 40px; color: #7f8c8d; }
        .record-details { 
            margin-top: 10px; 
            font-size: 0.85em; 
            color: #666; 
            background: #f8f9fa; 
            padding: 8px; 
            border-radius: 3px; 
            display: none;
        }
        .record-item.expanded .record-details { display: block; }
        .legend { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 15px; 
            margin: 15px 0; 
            padding: 10px; 
            background: #f8f9fa; 
            border-radius: 5px;
        }
        .legend-item { display: flex; align-items: center; font-size: 0.85em; }
        .legend-color { width: 20px; height: 20px; margin-right: 5px; border-radius: 3px; }
        .category-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-top: 20px; 
            padding: 10px; 
            background: #f8f9fa; 
            border-radius: 5px;
        }
        .category-count { background: #3498db; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.9em; }
        .status-badge { 
            display: inline-block; 
            padding: 2px 6px; 
            border-radius: 10px; 
            font-size: 0.8em; 
            margin-left: 5px;
        }
        .beide-oud-badge { background: #ff7675; color: white; }
        .created-oud-badge { background: #a29bfe; color: white; }
        .updated-oud-badge { background: #81ecec; color: #2d3436; }
        .beide-nieuw-badge { background: #55efc4; color: #2d3436; }
        .date-compare { 
            margin-top: 5px; 
            font-size: 0.85em; 
            color: #636e72;
            display: flex;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 AVG Register Beheer - Dual Date Check</h1>
        <p>Controleert zowel created_at als updated_at voor volledige ouderdomsanalyse</p>
        
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'verwijderd') !== false ? 'danger' : 'success'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card beide-oud">
                <div class="stat-number"><?php echo $stats['older_than_2_years_both']; ?></div>
                <div class="stat-label">Beide dates > 2 jaar</div>
            </div>
            <div class="stat-card created-oud">
                <div class="stat-number"><?php echo $stats['created_older_than_2_years']; ?></div>
                <div class="stat-label">Alleen created > 2 jaar</div>
            </div>
            <div class="stat-card updated-oud">
                <div class="stat-number"><?php echo $stats['updated_older_than_2_years']; ?></div>
                <div class="stat-label">Alleen updated > 2 jaar</div>
            </div>
            <div class="stat-card beide-nieuw">
                <div class="stat-number"><?php echo $stats['total_records']; ?></div>
                <div class="stat-label">Totaal Records</div>
            </div>
        </div>
        
        <div class="legend">
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(to bottom, #e74c3c 50%, #e74c3c 50%);"></div>
                <span>Beide dates > 2 jaar (archiveren)</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(to bottom, #e74c3c 50%, #3498db 50%);"></div>
                <span>Created > 2 jaar, Updated recenter</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(to bottom, #3498db 50%, #e74c3c 50%);"></div>
                <span>Created recenter, Updated > 2 jaar</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(to bottom, #2ecc71 50%, #2ecc71 50%);"></div>
                <span>Beide dates recent (< 2 jaar)</span>
            </div>
        </div>
        
        <?php if ($stats['older_than_2_years_both'] > 0): ?>
            <div class="message warning">
                ⚠️ Er zijn <?php echo $stats['older_than_2_years_both']; ?> records waar BEIDE datums (created_at EN updated_at) 
                ouder zijn dan 2 jaar. Dit zijn prime kandidaten voor archivering of verwijdering.
            </div>
        <?php endif; ?>
        
        <div class="selection-info">
            <span id="selectedCount">0</span> records geselecteerd voor verwijdering
            <span style="float: right;">
                <span id="selectedBeideOud" style="color: #e74c3c; margin-left: 10px;">0 beide oud</span>
                <span id="selectedCreatedOud" style="color: #a29bfe; margin-left: 10px;">0 created oud</span>
                <span id="selectedUpdatedOud" style="color: #81ecec; margin-left: 10px;">0 updated oud</span>
            </span>
        </div>
        
        <div class="form-container">
            <form method="POST" id="deleteForm">
                <input type="hidden" name="delete_ids" id="deleteIds">
                <button type="submit" name="delete_selected" class="btn btn-danger" id="deleteBtn" disabled
                        onclick="return confirm('WAARSCHUWING: Weet je zeker dat je de geselecteerde records PERMANENT wilt verwijderen? Dit kan niet ongedaan gemaakt worden!')">
                    🗑️ Verwijder Geselecteerde Records Permanent
                </button>
                <button type="button" class="btn btn-warning" onclick="selectAllBeideOud()">
                    📋 Selecteer Alle "Beide Oud" Records
                </button>
                <button type="button" class="btn btn-info" onclick="deselectAll()">
                    ❌ Deselecteer Alles
                </button>
            </form>
        </div>
        
        <!-- Records waar BEIDE datums oud zijn -->
        <?php if (!empty($categorizedRecords['beide_oud'])): ?>
            <div class="category-header">
                <h2>📅 Records waar BEIDE datums > 2 jaar oud zijn (<?php echo count($categorizedRecords['beide_oud']); ?>)</h2>
                <span class="category-count">Hoogste prioriteit voor archivering</span>
            </div>
            <div id="beideOudList">
                <?php foreach ($categorizedRecords['beide_oud'] as $record): ?>
                    <div class="record-item" 
                         data-id="<?php echo $record['id']; ?>"
                         data-status="beide_oud"
                         onclick="toggleSelection(<?php echo $record['id']; ?>, 'beide_oud', this)"
                         ondblclick="toggleDetails(this)">
                        <div class="date-indicator beide-oud-indicator"></div>
                        <span class="record-id">#<?php echo $record['id']; ?></span>
                        <span class="status-badge beide-oud-badge">BEIDE OUD</span>
                        <span class="record-age created-age" title="Created: <?php echo $record['created_at']; ?>">
                            Created: <?php echo $record['created_jaren_oud']; ?> jaar
                        </span>
                        <span class="record-age updated-age" title="Updated: <?php echo $record['updated_at']; ?>">
                            Updated: <?php echo $record['updated_jaren_oud']; ?> jaar
                        </span>
                        <span class="record-activity">
                            <?php echo htmlspecialchars(substr($record['verwerkingsactiviteit'], 0, 70)) . 
                                 (strlen($record['verwerkingsactiviteit']) > 70 ? '...' : ''); ?>
                        </span>
                        <div class="date-compare">
                            <span>Created: <?php echo date('d-m-Y', strtotime($record['created_at'])); ?></span>
                            <span>Updated: <?php echo date('d-m-Y', strtotime($record['updated_at'])); ?></span>
                            <span>Verschil: <?php echo abs($record['created_dagen_oud'] - $record['updated_dagen_oud']); ?> dagen</span>
                        </div>
                        <div class="record-details">
                            <strong>Risiconiveau:</strong> <?php echo $record['risiconiveau']; ?> | 
                            <strong>DPIA vereist:</strong> <?php echo $record['dpia_vereist']; ?><br>
                            <strong>Created exact:</strong> <?php echo date('d-m-Y H:i', strtotime($record['created_at'])); ?><br>
                            <strong>Updated exact:</strong> <?php echo date('d-m-Y H:i', strtotime($record['updated_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Records waar alleen created_at oud is -->
        <?php if (!empty($categorizedRecords['created_oud'])): ?>
            <div class="category-header">
                <h2>📝 Records waar alleen created_at > 2 jaar is (<?php echo count($categorizedRecords['created_oud']); ?>)</h2>
                <span class="category-count">Updated recent, created oud</span>
            </div>
            <div id="createdOudList">
                <?php foreach ($categorizedRecords['created_oud'] as $record): ?>
                    <div class="record-item" 
                         data-id="<?php echo $record['id']; ?>"
                         data-status="created_oud"
                         onclick="toggleSelection(<?php echo $record['id']; ?>, 'created_oud', this)"
                         ondblclick="toggleDetails(this)">
                        <div class="date-indicator created-oud-indicator"></div>
                        <span class="record-id">#<?php echo $record['id']; ?></span>
                        <span class="status-badge created-oud-badge">CREATED OUD</span>
                        <span class="record-age created-age" title="Created: <?php echo $record['created_at']; ?>">
                            Created: <?php echo $record['created_jaren_oud']; ?> jaar
                        </span>
                        <span class="record-age updated-age" title="Updated: <?php echo $record['updated_at']; ?>">
                            Updated: <?php echo floor($record['updated_dagen_oud']/365); ?> jaar
                        </span>
                        <span class="record-activity">
                            <?php echo htmlspecialchars(substr($record['verwerkingsactiviteit'], 0, 70)) . 
                                 (strlen($record['verwerkingsactiviteit']) > 70 ? '...' : ''); ?>
                        </span>
                        <div class="date-compare">
                            <span>Created: <?php echo date('d-m-Y', strtotime($record['created_at'])); ?></span>
                            <span>Updated: <?php echo date('d-m-Y', strtotime($record['updated_at'])); ?></span>
                        </div>
                        <div class="record-details">
                            <strong>Risiconiveau:</strong> <?php echo $record['risiconiveau']; ?> | 
                            <strong>DPIA vereist:</strong> <?php echo $record['dpia_vereist']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Records waar alleen updated_at oud is -->
        <?php if (!empty($categorizedRecords['updated_oud'])): ?>
            <div class="category-header">
                <h2>🔄 Records waar alleen updated_at > 2 jaar is (<?php echo count($categorizedRecords['updated_oud']); ?>)</h2>
                <span class="category-count">Created recent, updated oud</span>
            </div>
            <div id="updatedOudList">
                <?php foreach ($categorizedRecords['updated_oud'] as $record): ?>
                    <div class="record-item" 
                         data-id="<?php echo $record['id']; ?>"
                         data-status="updated_oud"
                         onclick="toggleSelection(<?php echo $record['id']; ?>, 'updated_oud', this)"
                         ondblclick="toggleDetails(this)">
                        <div class="date-indicator updated-oud-indicator"></div>
                        <span class="record-id">#<?php echo $record['id']; ?></span>
                        <span class="status-badge updated-oud-badge">UPDATED OUD</span>
                        <span class="record-age created-age" title="Created: <?php echo $record['created_at']; ?>">
                            Created: <?php echo floor($record['created_dagen_oud']/365); ?> jaar
                        </span>
                        <span class="record-age updated-age" title="Updated: <?php echo $record['updated_at']; ?>">
                            Updated: <?php echo $record['updated_jaren_oud']; ?> jaar
                        </span>
                        <span class="record-activity">
                            <?php echo htmlspecialchars(substr($record['verwerkingsactiviteit'], 0, 70)) . 
                                 (strlen($record['verwerkingsactiviteit']) > 70 ? '...' : ''); ?>
                        </span>
                        <div class="date-compare">
                            <span>Created: <?php echo date('d-m-Y', strtotime($record['created_at'])); ?></span>
                            <span>Updated: <?php echo date('d-m-Y', strtotime($record['updated_at'])); ?></span>
                        </div>
                        <div class="record-details">
                            <strong>Risiconiveau:</strong> <?php echo $record['risiconiveau']; ?> | 
                            <strong>DPIA vereist:</strong> <?php echo $record['dpia_vereist']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Overzicht van alle records -->
        <div class="category-header">
            <h2>📊 Alle Records (<?php echo count($allRecords); ?> van <?php echo $stats['total_records']; ?>)</h2>
            <span class="category-count">Recentste eerst</span>
        </div>
        <div id="allRecordsList">
            <?php foreach ($allRecords as $record): ?>
                <?php 
                $createdOld = $record['created_dagen'] > 730;
                $updatedOld = $record['updated_dagen'] > 730;
                $status = $createdOld && $updatedOld ? 'beide_oud' : 
                         ($createdOld ? 'created_oud' : 
                         ($updatedOld ? 'updated_oud' : 'beide_nieuw'));
                $indicatorClass = $status . '-indicator';
                $badgeClass = $status . '-badge';
                $badgeText = $status === 'beide_oud' ? 'BEIDE OUD' : 
                            ($status === 'created_oud' ? 'CREATED OUD' : 
                            ($status === 'updated_oud' ? 'UPDATED OUD' : 'RECENT'));
                ?>
                <div class="record-item" ondblclick="toggleDetails(this)">
                    <div class="date-indicator <?php echo $indicatorClass; ?>"></div>
                    <span class="record-id">#<?php echo $record['id']; ?></span>
                    <span class="status-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                    <span class="record-age created-age">
                        C: <?php echo floor($record['created_dagen']/365); ?>j
                    </span>
                    <span class="record-age updated-age">
                        U: <?php echo floor($record['updated_dagen']/365); ?>j
                    </span>
                    <span class="record-activity">
                        <?php echo htmlspecialchars(substr($record['verwerkingsactiviteit'], 0, 50)) . 
                             (strlen($record['verwerkingsactiviteit']) > 50 ? '...' : ''); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        let selectedIds = [];
        let selectedByStatus = {
            'beide_oud': 0,
            'created_oud': 0,
            'updated_oud': 0
        };
        
        function toggleSelection(id, status, element) {
            const index = selectedIds.indexOf(id);
            
            if (index === -1) {
                // Toevoegen aan selectie
                selectedIds.push(id);
                selectedByStatus[status]++;
                element.classList.add('selected');
            } else {
                // Verwijderen uit selectie
                selectedIds.splice(index, 1);
                selectedByStatus[status]--;
                element.classList.remove('selected');
            }
            
            updateSelectionUI();
        }
        
        function toggleDetails(element) {
            element.classList.toggle('expanded');
        }
        
        function selectAllBeideOud() {
            const beideOudRecords = document.querySelectorAll('#beideOudList .record-item');
            
            beideOudRecords.forEach(item => {
                const id = parseInt(item.getAttribute('data-id'));
                const status = item.getAttribute('data-status');
                
                if (!selectedIds.includes(id)) {
                    selectedIds.push(id);
                    selectedByStatus[status]++;
                    item.classList.add('selected');
                }
            });
            
            updateSelectionUI();
        }
        
        function deselectAll() {
            selectedIds = [];
            selectedByStatus = {
                'beide_oud': 0,
                'created_oud': 0,
                'updated_oud': 0
            };
            
            document.querySelectorAll('.record-item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            updateSelectionUI();
        }
        
        function updateSelectionUI() {
            const selectedCount = document.getElementById('selectedCount');
            const deleteBtn = document.getElementById('deleteBtn');
            const deleteIdsInput = document.getElementById('deleteIds');
            
            // Update counts
            document.getElementById('selectedBeideOud').textContent = selectedByStatus.beide_oud + ' beide oud';
            document.getElementById('selectedCreatedOud').textContent = selectedByStatus.created_oud + ' created oud';
            document.getElementById('selectedUpdatedOud').textContent = selectedByStatus.updated_oud + ' updated oud';
            
            selectedCount.textContent = selectedIds.length;
            deleteBtn.disabled = selectedIds.length === 0;
            deleteIdsInput.value = selectedIds.join(',');
            
            if (selectedIds.length > 0) {
                selectedCount.style.color = '#e74c3c';
                selectedCount.style.fontWeight = 'bold';
            } else {
                selectedCount.style.color = '';
                selectedCount.style.fontWeight = '';
            }
            
            // Update button text based on selection
            if (selectedByStatus.beide_oud > 0) {
                deleteBtn.innerHTML = `🗑️ Verwijder ${selectedIds.length} Records (${selectedByStatus.beide_oud} beide oud)`;
            } else {
                deleteBtn.innerHTML = `🗑️ Verwijder ${selectedIds.length} Records`;
            }
        }
        
        // Voorkom dubbelklik selectie van tekst
        document.addEventListener('mousedown', function(e) {
            if (e.detail > 1) {
                e.preventDefault();
            }
        }, false);
        
        // Initialiseer UI
        updateSelectionUI();
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                deselectAll();
            }
            if (e.key === 'a' && e.ctrlKey) {
                e.preventDefault();
                selectAllBeideOud();
            }
        });
    </script>
</body>
</html>