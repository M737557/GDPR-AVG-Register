

-- Voeg dan de kolommen toe op de juiste positie (pas aan op basis van je bestaande structuur)
ALTER TABLE verwerkingen
ADD COLUMN naam_verwerkingsverantwoordelijke VARCHAR(255) AFTER verwerkingsactiviteit,
ADD COLUMN contact_verwerkingsverantwoordelijke VARCHAR(255) AFTER naam_verwerkingsverantwoordelijke,
ADD COLUMN naam_gezamenlijke_verwerkingsverantwoordelijke VARCHAR(255) AFTER contact_verwerkingsverantwoordelijke,
ADD COLUMN contact_gezamenlijke_verwerkingsverantwoordelijke VARCHAR(255) AFTER naam_gezamenlijke_verwerkingsverantwoordelijke,
ADD COLUMN naam_vertegenwoordiger VARCHAR(255) AFTER contact_gezamenlijke_verwerkingsverantwoordelijke,
ADD COLUMN contact_vertegenwoordiger VARCHAR(255) AFTER naam_vertegenwoordiger,
ADD COLUMN naam_fg VARCHAR(255) AFTER contact_vertegenwoordiger,
ADD COLUMN contact_fg VARCHAR(255) AFTER naam_fg;