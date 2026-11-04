-- =====================================================================
-- Pet Adoption Matcher  |  IM2 Final Project  |  XAMPP MySQL / MariaDB
-- Import with phpMyAdmin: Import tab -> choose this file -> Go
-- WARNING: drops and recreates the pet_adoption_matcher database.
-- =====================================================================
DROP DATABASE IF EXISTS pet_adoption_matcher;
CREATE DATABASE pet_adoption_matcher CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pet_adoption_matcher;

-- ---------------------------------------------------------------------
-- TABLES (7)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,                       -- bcrypt via password_hash()
  full_name     VARCHAR(100) NOT NULL,
  role          ENUM('admin','staff','visitor') NOT NULL DEFAULT 'visitor',
  supervisor_id INT NULL,                                    -- self-referencing hierarchy
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE adopters (
  adopter_id        INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NULL UNIQUE,                         -- set when a Visitor owns this profile
  first_name        VARCHAR(50)  NOT NULL,
  last_name         VARCHAR(50)  NOT NULL,
  email             VARCHAR(100) NOT NULL UNIQUE,
  contact_no        VARBINARY(255) NOT NULL,                 -- AES encrypted at rest
  housing_type      ENUM('Apartment','House') NOT NULL,
  has_kids          TINYINT(1) NOT NULL DEFAULT 0,
  has_other_pets    TINYINT(1) NOT NULL DEFAULT 0,
  activity_level    ENUM('Low','Moderate','High') NOT NULL,
  preferred_species ENUM('Dog','Cat','Any') NOT NULL DEFAULT 'Any',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_adopters_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE pets (
  pet_id        INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(50) NOT NULL,
  species       ENUM('Dog','Cat') NOT NULL,
  breed         VARCHAR(60) NOT NULL,
  sex           ENUM('Male','Female') NOT NULL,
  age_months    INT NOT NULL CHECK (age_months >= 0),
  size          ENUM('Small','Medium','Large') NOT NULL,
  energy_level  ENUM('Low','Moderate','High') NOT NULL,
  is_vaccinated TINYINT(1) NOT NULL DEFAULT 0,
  status        ENUM('Available','Pending','Adopted') NOT NULL DEFAULT 'Available',
  description   TEXT NULL,
  intake_date   DATE NOT NULL
) ENGINE=InnoDB;

CREATE TABLE traits (
  trait_id    INT AUTO_INCREMENT PRIMARY KEY,
  trait_name  VARCHAR(40) NOT NULL UNIQUE,
  description VARCHAR(150) NULL
) ENGINE=InnoDB;

CREATE TABLE pet_traits (                                    -- junction table (many-to-many)
  pet_id   INT NOT NULL,
  trait_id INT NOT NULL,
  PRIMARY KEY (pet_id, trait_id),
  CONSTRAINT fk_pt_pet   FOREIGN KEY (pet_id)   REFERENCES pets(pet_id)     ON DELETE CASCADE,
  CONSTRAINT fk_pt_trait FOREIGN KEY (trait_id) REFERENCES traits(trait_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE applications (
  application_id INT AUTO_INCREMENT PRIMARY KEY,
  adopter_id     INT NOT NULL,
  pet_id         INT NOT NULL,
  applied_by     INT NOT NULL,
  decided_by     INT NULL,
  match_score    INT NOT NULL DEFAULT 0 CHECK (match_score BETWEEN 0 AND 100),
  status         ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  applied_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at     DATETIME NULL,
  CONSTRAINT fk_app_adopter    FOREIGN KEY (adopter_id) REFERENCES adopters(adopter_id),
  CONSTRAINT fk_app_pet        FOREIGN KEY (pet_id)     REFERENCES pets(pet_id),
  CONSTRAINT fk_app_applied_by FOREIGN KEY (applied_by) REFERENCES users(user_id),
  CONSTRAINT fk_app_decided_by FOREIGN KEY (decided_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  log_id     BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NULL,
  action     VARCHAR(30)  NOT NULL,
  table_name VARCHAR(40)  NOT NULL,
  record_id  INT NULL,
  details    VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- INDEXES (performance: see performance_test.sql for before/after)
-- ---------------------------------------------------------------------
CREATE INDEX idx_pets_status_species   ON pets(status, species);
CREATE INDEX idx_applications_status   ON applications(status, decided_at);
CREATE INDEX idx_audit_created         ON audit_log(created_at);

-- ---------------------------------------------------------------------
-- VIEWS
-- ---------------------------------------------------------------------
CREATE VIEW v_available_pets AS
SELECT p.pet_id, p.name, p.species, p.breed, p.sex, p.age_months, p.size, p.energy_level,
       p.is_vaccinated, p.description,
       (SELECT GROUP_CONCAT(t.trait_name ORDER BY t.trait_name SEPARATOR ', ')
          FROM pet_traits pt JOIN traits t ON t.trait_id = pt.trait_id
         WHERE pt.pet_id = p.pet_id) AS traits
FROM pets p
WHERE p.status = 'Available';

CREATE VIEW v_application_details AS
SELECT a.application_id, a.adopter_id, a.pet_id,
       CONCAT(ad.first_name, ' ', ad.last_name) AS adopter_name,
       p.name AS pet_name, p.species, a.match_score, a.status,
       u1.full_name AS applied_by_name, u2.full_name AS decided_by_name,
       a.applied_at, a.decided_at
FROM applications a
JOIN adopters ad ON ad.adopter_id = a.adopter_id
JOIN pets p      ON p.pet_id      = a.pet_id
JOIN users u1    ON u1.user_id    = a.applied_by
LEFT JOIN users u2 ON u2.user_id  = a.decided_by;

DELIMITER $$

-- ---------------------------------------------------------------------
-- USER-DEFINED FUNCTION: match score 0-100
--   species 30 + energy 30 (15 if one step apart) + kids 15 + other pets 10 + housing 15
-- ---------------------------------------------------------------------
CREATE FUNCTION fn_match_score(
  p_pet_id INT, p_housing VARCHAR(10), p_kids TINYINT, p_others TINYINT,
  p_activity VARCHAR(10), p_species VARCHAR(10)
) RETURNS INT
READS SQL DATA
BEGIN
  DECLARE v_species VARCHAR(10);
  DECLARE v_size    VARCHAR(10);
  DECLARE v_energy  VARCHAR(10);
  DECLARE v_score   INT DEFAULT 0;
  DECLARE v_gap     INT;

  SELECT species, size, energy_level INTO v_species, v_size, v_energy
    FROM pets WHERE pet_id = p_pet_id;
  IF v_species IS NULL THEN RETURN 0; END IF;

  IF p_species = 'Any' OR p_species = v_species THEN SET v_score = v_score + 30; END IF;

  SET v_gap = ABS(FIELD(p_activity,'Low','Moderate','High') - FIELD(v_energy,'Low','Moderate','High'));
  IF v_gap = 0 THEN SET v_score = v_score + 30;
  ELSEIF v_gap = 1 THEN SET v_score = v_score + 15;
  END IF;

  IF p_kids = 0 OR EXISTS (SELECT 1 FROM pet_traits pt JOIN traits t ON t.trait_id = pt.trait_id
                            WHERE pt.pet_id = p_pet_id AND t.trait_name = 'Good with kids') THEN
    SET v_score = v_score + 15;
  END IF;

  IF p_others = 0 OR EXISTS (SELECT 1 FROM pet_traits pt JOIN traits t ON t.trait_id = pt.trait_id
                              WHERE pt.pet_id = p_pet_id AND t.trait_name = 'Good with other pets') THEN
    SET v_score = v_score + 10;
  END IF;

  IF p_housing = 'House' OR v_size <> 'Large' THEN SET v_score = v_score + 15; END IF;

  RETURN v_score;
END$$

-- ---------------------------------------------------------------------
-- STORED PROCEDURE 1 / TRANSACTION 1: submit an adoption application
-- ---------------------------------------------------------------------
CREATE PROCEDURE sp_submit_application(
  IN p_adopter_id INT, IN p_pet_id INT, IN p_user_id INT,
  OUT p_code INT, OUT p_msg VARCHAR(150)
)
BEGIN
  DECLARE v_status   VARCHAR(10) DEFAULT NULL;
  DECLARE v_housing  VARCHAR(10) DEFAULT NULL;
  DECLARE v_kids     TINYINT;
  DECLARE v_others   TINYINT;
  DECLARE v_activity VARCHAR(10);
  DECLARE v_pref     VARCHAR(10);

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET p_code = 1;
    SET p_msg  = 'Database error. Nothing was saved.';
  END;

  SET @current_user_id = p_user_id;          -- used by the audit triggers
  START TRANSACTION;

  SELECT status INTO v_status FROM pets WHERE pet_id = p_pet_id FOR UPDATE;   -- lock the pet row
  SELECT housing_type, has_kids, has_other_pets, activity_level, preferred_species
    INTO v_housing, v_kids, v_others, v_activity, v_pref
    FROM adopters WHERE adopter_id = p_adopter_id;

  IF v_status IS NULL THEN
    ROLLBACK; SET p_code = 2; SET p_msg = 'Pet not found.';
  ELSEIF v_housing IS NULL THEN
    ROLLBACK; SET p_code = 4; SET p_msg = 'Adopter not found.';
  ELSEIF v_status <> 'Available' THEN
    ROLLBACK; SET p_code = 3; SET p_msg = 'This pet is not available anymore.';
  ELSE
    INSERT INTO applications (adopter_id, pet_id, applied_by, match_score, status)
    VALUES (p_adopter_id, p_pet_id, p_user_id,
            fn_match_score(p_pet_id, v_housing, v_kids, v_others, v_activity, v_pref), 'Pending');
    UPDATE pets SET status = 'Pending' WHERE pet_id = p_pet_id;
    COMMIT;
    SET p_code = 0; SET p_msg = 'Application saved. The pet is now Pending.';
  END IF;
END$$

-- ---------------------------------------------------------------------
-- STORED PROCEDURE 2 / TRANSACTION 2: approve or reject an application
-- ---------------------------------------------------------------------
CREATE PROCEDURE sp_decide_application(
  IN p_application_id INT, IN p_decision VARCHAR(10), IN p_user_id INT,
  OUT p_code INT, OUT p_msg VARCHAR(150)
)
BEGIN
  DECLARE v_pet    INT DEFAULT NULL;
  DECLARE v_status VARCHAR(10) DEFAULT NULL;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET p_code = 1;
    SET p_msg  = 'Database error. Nothing was saved.';
  END;

  SET @current_user_id = p_user_id;

  IF p_decision NOT IN ('Approved','Rejected') THEN
    SET p_code = 5; SET p_msg = 'Decision must be Approved or Rejected.';
  ELSE
    START TRANSACTION;
    -- lock order: pet first, then application (same order as sp_submit_application -> no deadlock)
    SELECT pet_id INTO v_pet FROM applications WHERE application_id = p_application_id;
    SELECT pet_id INTO v_pet FROM pets WHERE pet_id = v_pet FOR UPDATE;
    SELECT status INTO v_status FROM applications WHERE application_id = p_application_id FOR UPDATE;

    IF v_status IS NULL THEN
      ROLLBACK; SET p_code = 2; SET p_msg = 'Application not found.';
    ELSEIF v_status <> 'Pending' THEN
      ROLLBACK; SET p_code = 3; SET p_msg = 'This application was already decided.';
    ELSEIF p_decision = 'Approved' THEN
      UPDATE applications SET status = 'Approved', decided_by = p_user_id, decided_at = NOW()
       WHERE application_id = p_application_id;
      UPDATE pets SET status = 'Adopted' WHERE pet_id = v_pet;
      UPDATE applications SET status = 'Rejected', decided_by = p_user_id, decided_at = NOW()
       WHERE pet_id = v_pet AND status = 'Pending' AND application_id <> p_application_id;
      COMMIT;
      SET p_code = 0; SET p_msg = 'Adoption approved. The pet is now Adopted.';
    ELSE
      UPDATE applications SET status = 'Rejected', decided_by = p_user_id, decided_at = NOW()
       WHERE application_id = p_application_id;
      UPDATE pets SET status = 'Available' WHERE pet_id = v_pet;
      COMMIT;
      SET p_code = 0; SET p_msg = 'Application rejected. The pet is Available again.';
    END IF;
  END IF;
END$$

-- ---------------------------------------------------------------------
-- STORED PROCEDURE 3: rank available pets for a set of preferences
-- uses a CTE and the RANK() window function
-- ---------------------------------------------------------------------
CREATE PROCEDURE sp_rank_matches(
  IN p_housing VARCHAR(10), IN p_kids TINYINT, IN p_others TINYINT,
  IN p_activity VARCHAR(10), IN p_species VARCHAR(10)
)
BEGIN
  WITH scored AS (
    SELECT v.pet_id, v.name, v.species, v.breed, v.age_months, v.size, v.energy_level, v.traits,
           fn_match_score(v.pet_id, p_housing, p_kids, p_others, p_activity, p_species) AS score
    FROM v_available_pets v
  )
  SELECT RANK() OVER (ORDER BY score DESC) AS rank_no,
         pet_id, name, species, breed, age_months, size, energy_level, traits, score
  FROM scored
  ORDER BY rank_no, name;
END$$

-- ---------------------------------------------------------------------
-- TRIGGERS (audit log). @current_user_id is set by the app / procedures.
-- ---------------------------------------------------------------------
CREATE TRIGGER trg_applications_ai AFTER INSERT ON applications FOR EACH ROW
BEGIN
  INSERT INTO audit_log (user_id, action, table_name, record_id, details)
  VALUES (@current_user_id, 'CREATE', 'applications', NEW.application_id,
          CONCAT('Application for pet #', NEW.pet_id, ' by adopter #', NEW.adopter_id));
END$$

CREATE TRIGGER trg_applications_au AFTER UPDATE ON applications FOR EACH ROW
BEGIN
  IF OLD.status <> NEW.status THEN
    INSERT INTO audit_log (user_id, action, table_name, record_id, details)
    VALUES (@current_user_id, 'DECISION', 'applications', NEW.application_id,
            CONCAT('Status ', OLD.status, ' -> ', NEW.status));
  END IF;
END$$

CREATE TRIGGER trg_pets_ad AFTER DELETE ON pets FOR EACH ROW
BEGIN
  INSERT INTO audit_log (user_id, action, table_name, record_id, details)
  VALUES (@current_user_id, 'DELETE', 'pets', OLD.pet_id, CONCAT('Deleted pet ', OLD.name));
END$$

CREATE TRIGGER trg_adopters_ad AFTER DELETE ON adopters FOR EACH ROW
BEGIN
  INSERT INTO audit_log (user_id, action, table_name, record_id, details)
  VALUES (@current_user_id, 'DELETE', 'adopters', OLD.adopter_id,
          CONCAT('Deleted adopter ', OLD.first_name, ' ', OLD.last_name));
END$$

DELIMITER ;

-- ---------------------------------------------------------------------
-- SAMPLE DATA (users + sample applications are created by setup.php
-- so that passwords are hashed with PHP password_hash())
-- ---------------------------------------------------------------------
INSERT INTO traits (trait_id, trait_name, description) VALUES
 (1,'Good with kids','Safe and gentle around children'),
 (2,'Good with other pets','Gets along with other dogs or cats'),
 (3,'House-trained','Already knows where to go'),
 (4,'Playful','Loves games and walks'),
 (5,'Calm','Relaxed and quiet'),
 (6,'Low maintenance','Easy grooming and care');

INSERT INTO pets (pet_id, name, species, breed, sex, age_months, size, energy_level, is_vaccinated, status, description, intake_date) VALUES
 (1,'Bantay','Dog','Aspin','Male',24,'Medium','High',1,'Available','Loyal and always ready to play fetch.','2026-08-01'),
 (2,'Mochi','Cat','Puspin','Female',10,'Small','Moderate',1,'Available','Curious kitten who loves window naps.','2026-08-14'),
 (3,'Kisses','Dog','Shih Tzu','Female',36,'Small','Moderate',1,'Available','Gentle lap dog, great for small homes.','2026-08-20'),
 (4,'Hunter','Dog','Labrador Mix','Male',18,'Large','High',1,'Available','Strong swimmer, needs lots of exercise.','2026-09-02'),
 (5,'Muning','Cat','Puspin','Female',48,'Small','Low',1,'Available','Quiet senior who enjoys cuddles.','2026-09-05'),
 (6,'Biscuit','Dog','Beagle','Male',30,'Medium','Moderate',0,'Available','Friendly nose-first explorer.','2026-09-11'),
 (7,'Luna','Cat','Persian Mix','Female',60,'Small','Low',1,'Available','Fluffy, calm, loves being brushed.','2026-09-18'),
 (8,'Brownie','Dog','Aspin','Female',8,'Medium','High',0,'Available','Energetic puppy still learning manners.','2026-10-01'),
 (9,'Tikoy','Cat','Puspin','Male',14,'Small','Moderate',1,'Adopted','Found a home in August.','2026-06-10'),
 (10,'Choco','Dog','Poodle Mix','Male',40,'Small','Moderate',1,'Adopted','Found a home in September.','2026-06-22'),
 (11,'Pepper','Dog','Aspin','Female',22,'Medium','Moderate',1,'Adopted','Found a home in September.','2026-07-03'),
 (12,'Whiskers','Cat','Siamese Mix','Male',28,'Small','Low',1,'Adopted','Found a home in October.','2026-07-15');

INSERT INTO pet_traits (pet_id, trait_id) VALUES
 (1,1),(1,2),(1,4), (2,1),(2,3),(2,5), (3,1),(3,3),(3,6), (4,1),(4,4),
 (5,3),(5,5),(5,6), (6,1),(6,2),(6,4), (7,3),(7,5),(7,6), (8,4),
 (9,1),(9,3), (10,1),(10,3), (11,2),(11,3), (12,5),(12,6);

INSERT INTO adopters (adopter_id, first_name, last_name, email, contact_no, housing_type, has_kids, has_other_pets, activity_level, preferred_species) VALUES
 (1,'Maria','Santos','maria.santos@example.com',AES_ENCRYPT('09171234567','PAM_SecretKey_2026'),'Apartment',0,0,'Low','Cat'),
 (2,'Juan','Dela Cruz','juan.delacruz@example.com',AES_ENCRYPT('09181234567','PAM_SecretKey_2026'),'House',1,1,'High','Dog'),
 (3,'Ana','Reyes','ana.reyes@example.com',AES_ENCRYPT('09191234567','PAM_SecretKey_2026'),'Apartment',0,1,'Moderate','Any'),
 (4,'Carlo','Lim','carlo.lim@example.com',AES_ENCRYPT('09201234567','PAM_SecretKey_2026'),'House',1,0,'Moderate','Dog');
