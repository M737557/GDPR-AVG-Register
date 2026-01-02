# GDPR-AVG-Register
ai AVG Register in het Nederlands op basis van deepseek.com 

Eigenlijk is er geen php mysql AVG register beschikbaar online of het is een excel sheet.
Daarom heb ik met een ai AVG register gemaakt op basis van een onderwijs instelling als basis.

In het voorbeeld een onderwijs avg register als basis.
Wil je zelf een ai avg register opstellen? Ga dan aan de slag met de import.sql met deepseek.com
Laat de import.sql in deepseek.com en geef daarna de volgende opdracht:

'maak een avg register voor de Toerisme sector in een mega dump in deze structuur', zonder de quotes.
Daarna maakt het een 100 activiteiten avg register, voldoende om mee aan de slag te gaan als basis.
Gebruik de andere tools om data te bekijken of bewaartermijn om te kijken hoe oud de data is, de opzet was om met data retentie verder te gaan door alleen de mysql kolommen created_at en updatet_at in de gaten te houden. Om meer linair andere tools te ontwikkelen op basis van 2 jaar of andere retentie beleid.

Bij vragen ben ik beschikbaar op:
matijn@gmail.com

Buy me coffee:
paypal account: matijn@gmail.com

MIT license is in het kort: mag niet worden verkocht als software wel gratis te gebruiken en te herschrijven.

Op volgorde runnen
Uitleg individuele bestanden:
1userstable.sql
//inloggen admin/admin123

2mysqladd2columns.php
//php bestand om 2 kolommen toe te voegen aan de database, benodigd om te zeggen wij zijn verantwoordelijk als verwerker ja/nee of derdepartij is verantwoordelijk ja/nee
//zonder deze heb je er weinig aan.

2systemchanges.sql
//Review modus vereist een tabel. Hiermee heb je track op wijzigingen ten opzichten van start op de database

basic_viewer.php
//Eigenlijk simpel kijken welke activiteiten je wil verwijderen of behouden. Snelle review doen.

leesbaar.php
//Eigenlijk een table dump maar dan zonder compact view. 

viewer.php
//AVG register viewer

De volgende databasetabel (avg_register) kolommen zijn gespeudonimseerd opgeslagen in de database en zijn zelf in te vullen op basis van contracten of intern personeel.
naam_verwerkingsverantwoordelijke
contact_verwerkingsverantwoordelijke
naam_gezamenlijke_verwerkingsverantwoordelijke
contact_gezamenlijke_verwerkingsverantwoordelijke
naam_vertegenwoordiger
contact_vertegenwoordiger
naam_fg
contact_fg

Veel succes.





