# GDPR-AVG-Register
ai AVG Register in het Nederlands op basis van deepseek.com 

Eigenlijk is er geen php mysql AVG register beschikbaar online of het is een excel sheet.
Daarom heb ik met een ai AVG register gemaakt op basis van een onderwijs instelling als basis.

In het voorbeeld een onderwijs avg register als basis.
Wil je zelf een ai avg register opstellen? Ga dan aan de slag met de import.sql met deepseek.com ai tool.
Laad de import.sql in deepseek.com en geef daarna de volgende opdracht:

'maak een avg register voor de Toerisme sector in een mega dump in deze structuur', zonder de quotes. Toerisme sector is even het voorbeeld.
Daarna maakt het een 100 activiteiten avg register, voldoende om mee aan de slag te gaan als basis.
Gebruik de andere tools om de datase eerst te bewerken (1),(2),(2),(3). direct in phpmyadmin SQL van de database. Begin met import.sql Gebruik bewaartermijn.php om te kijken hoe oud de data is, deze is zo opzet dat het in de toekomst omgaat met laatste twee database kolommen - voor data retentie tools( created_at en updatet_at) in de gaten te houden. Om meer lineair andere tools te ontwikkelen op basis van 2 jaar of andere retentie beleid...

Bij vragen ben ik beschikbaar:

MIT license is in het kort: mag niet worden doorverkocht als software wel gratis te gebruiken en te herschrijven.

Op volgorde uitvoeren files (1)(2)(2)(3):

Uitleg individuele bestanden.
1userstable.sql
Maak gebruikers aan op basis van standaard passwordhash functie php
admin user is: admin/admin123 zie ook de webinterface bij runnen viewer.php

2mysqladd2columns.php
//php bestand om 2 kolommen toe te voegen aan de database, benodigd om te zeggen wij zijn verantwoordelijk als verwerker ja/nee of derdepartij is verantwoordelijk ja/nee
//deze run is vereist.

2systemchanges.sql
//Review modus vereist een tabel. Hiermee heb je track op wijzigingen ten opzichten van start op de database
//deze run is vereist.

3addcolumnsAVGregister
//toevoegen gespeudonimseerde kolommen aan database
//deze run is vereist.

basic_viewer.php
//Eigenlijk simpel kijken welke activiteiten je wil verwijderen of behouden. Snelle review doen.

viewer.php
//AVG register viewer beheer hier jouw avg register.

De volgende databasetabel (avg_register) kolommen zijn gespeudonimseerd opgeslagen(avg proof zie hiervoor de php code) in de database en zijn zelf in te vullen op basis van contracten of intern personeel.

naam_verwerkingsverantwoordelijke,
contact_verwerkingsverantwoordelijke,
naam_gezamenlijke_verwerkingsverantwoordelijke,
contact_gezamenlijke_verwerkingsverantwoordelijke,
naam_vertegenwoordiger,
contact_vertegenwoordiger,
naam_fg,
contact_fg

Veel succes, bij vragen kan het even duren voor ik reageer.
Komen er nieuwe functie verzoeken post ik hieronder updates.
Voor nieuwe functies ben ik bereikbaar. Stuur een berichtje en vergeet mij geen koffie te doneren ;-)

Engelse vertaling, andere vertalingen of php funcies of vragen zijn op verzoek aan te vragen per e-mail.
Met vriendelijke groet
Matijn

Releases
first release v.01

second release v.02
Updates/nieuwe functies:
viewer versie 12

Third release v.03
Updates/nieuwe functies:
viewer versie 15

4th release v.04 2026-01-16
Updates/nieuwe functies:
basic
basic_viewer-exporthtml.php


5th release v.05 2026-01-16
Updates/nieuwe functies:
viewer17.php 
(removed footer from pages - compact print and Print All Records with DPIA Details (button functions).


6th release v.06 2026-01-19 15:42 (CET)
Updates/nieuwe functies:
viewer18.php 

fixed error (is_active)

dpa_vereist (compact print) was not displaying correct.

added rot47 custom Pseudonymization in code. function rot

rot47_encrypt, rot47_decrypt are now custom build for you, rotate the ciphers..


New Goodies are:
all_organizational -dedup.php
all_technicals -dedup.php

7th release v.06 2026-01-19 08:21 (CET)
ManageUsersWeb.php
(manage TOTP / multifactor keys from table system_users). 
If table not exists, creates it.






