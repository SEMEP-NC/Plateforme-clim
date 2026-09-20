-- Support multi-type de climatiseurs (GMV centralisé / Splits)
--
-- - equipments / discovered_units : colonne `type` pour savoir quelle carte
--   de registres Modbus appliquer (voir shared/registers.py).
-- - discovery_config : passe d'une configuration unique à plusieurs
--   passerelles nommées, chacune avec son propre type. La ligne existante
--   (id=1) est conservée et étiquetée GMV pour ne rien casser.

ALTER TABLE equipments
    ADD COLUMN type ENUM('GMV','SPLIT') NOT NULL DEFAULT 'GMV' AFTER UI;

ALTER TABLE discovered_units
    ADD COLUMN type ENUM('GMV','SPLIT') NOT NULL DEFAULT 'GMV' AFTER model;

ALTER TABLE discovery_config
    ADD COLUMN name VARCHAR(100) NOT NULL DEFAULT 'Configuration' AFTER id,
    ADD COLUMN type ENUM('GMV','SPLIT') NOT NULL DEFAULT 'GMV' AFTER slave_ids;

UPDATE discovery_config
SET name = 'GMV (par défaut)', type = 'GMV'
WHERE id = 1;
