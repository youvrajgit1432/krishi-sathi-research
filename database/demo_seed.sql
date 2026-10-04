-- ============================================================
-- Krishi Sathi Research - fictional demo data
-- Target database: krishi_sathi_research_demo
-- ALL DATA IS FICTIONAL. No real farmers, participants or interviews.
-- Import after database/schema.sql.
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `research_users` (`id`,`username`,`password`,`full_name`,`phone`,`email`,`role`,`status`,`force_password_change`,`is_deleted`) VALUES
  (1,'demo_research','$2y$10$2Y0lw9jELdSSg0GkM8bOrO1hJt9VgFULvhqTufv1ptVuuGDYoKLJ.','Demo Research Lead',NULL,'demo.research@example.test','lead','active',0,0),
  (2,'demo_editor','$2y$10$PxfjY0eS5QJkwLjZIvhhkuWFEVGRAECgw2Iy12BDPcnmdSXfk5qKm','Demo Editor',NULL,'demo.editor@example.test','editor','active',0,0),
  (3,'demo_contributor','$2y$10$40mlYB90xUlD.y0b1Fq0I.EVVqPENTjJXtqvKzY0H6RovweBsse7u','Demo Contributor',NULL,'demo.contributor@example.test','contributor','active',0,0),
  (4,'demo_viewer','$2y$10$1Fg393OgNWoH/ZtLsyxgauWWsablAgXe/o8N9IXjJxmo6syNipsw.','Demo Viewer',NULL,'demo.viewer@example.test','viewer','active',0,0);

INSERT INTO `location_provinces` (`id`,`name`) VALUES (1,'Demo Province');
INSERT INTO `location_districts` (`id`,`province_id`,`name`) VALUES (1,1,'Demo District');
INSERT INTO `location_municipalities` (`id`,`district_id`,`name`,`municipality_type`) VALUES
  (1,1,'Demotown','Municipality'),
  (2,1,'Demovillage','Rural Municipality');

INSERT INTO `research_farmers`
  (`id`,`farmer_id`,`name`,`phone`,`district`,`municipality`,`ward`,`tole`,`latitude`,`longitude`,`approval_status`,`age_group`,`gender`,`education_level`,`primary_occupation`,`land_ownership_type`,`irrigation_access`,`preferred_language`,`created_by`,`is_deleted`)
VALUES
  (1,'FRM-00001','Demo Farmer One','9800000101','Demo District','Demotown','1','Demo Tole A',27.71000000,85.31000000,'approved','36-45','male','secondary','farming','owned','rainfed','Nepali',1,0),
  (2,'FRM-00002','Demo Farmer Two','9800000102','Demo District','Demovillage','2','Demo Tole B',27.72000000,85.32000000,'approved','26-35','female','primary','farming','leased','irrigated','Nepali',1,0);

INSERT INTO `research_participants`
  (`id`,`participant_id`,`participant_type`,`name`,`phone`,`organization`,`district`,`municipality`,`ward`,`tole`,`approval_status`,`experience_years`,`service_area`,`main_work_area`,`primary_role`,`created_by`,`is_deleted`)
VALUES
  (1,'PAR-00001','service_provider','Demo Provider One','9800000201','Demo Cooperative','Demo District','Demotown','1','Demo Tole A','approved',8,'Demotown','Input supply','agro-dealer',1,0),
  (2,'PAR-00002','expert','Demo Expert Two','9800000202','Demo Agriculture Office','Demo District','Demovillage','2','Demo Tole B','approved',12,'Demo District','Extension','extension-officer',1,0);

INSERT INTO `research_farm_profiles` (`id`,`farmer_id`,`farm_type`,`farm_size`,`land_unit`,`years_farming`,`is_commercial`) VALUES
  (1,1,'mixed',2.50,'ropani',15,0),
  (2,2,'vegetables',1.00,'ropani',7,1);

INSERT INTO `research_interviews`
  (`id`,`farmer_id`,`interview_date`,`interviewer_id`,`interview_round`,`interview_mode`,`interview_language`,`duration_minutes`,`interview_status`,`interview_summary`,`problem_severity`,`is_deleted`)
VALUES
  (1,1,'2026-01-10',1,1,'in_person','Nepali',45,'approved','Fictional baseline interview.',3,0),
  (2,1,'2026-02-10',3,2,'phone','Nepali',30,'submitted','Fictional follow-up interview.',2,0),
  (3,2,'2026-01-15',1,1,'in_person','Nepali',40,'approved','Fictional baseline interview.',3,0),
  (4,2,'2026-02-15',3,2,'in_person','Nepali',35,'approved','Fictional follow-up interview.',2,0),
  (5,1,'2026-03-01',2,3,'phone','Nepali',25,'draft','Fictional third-round interview.',1,0);

INSERT INTO `research_observations`
  (`id`,`farmer_id`,`participant_id`,`interview_id`,`observer_id`,`observation_date`,`observed_smartphone_usage`,`observed_record_books`,`researcher_notes`,`general_notes`,`is_deleted`)
VALUES
  (1,1,NULL,1,1,'2026-01-10','Yes','No','Fictional observation of smartphone use.','Demo note.',0),
  (2,2,NULL,3,3,'2026-01-15','No','Yes','Fictional observation of record keeping.','Demo note.',0);

INSERT INTO `research_consent_records`
  (`id`,`farmer_id`,`participant_id`,`consent_date`,`consent_type`,`consent_status`,`consent_method`,`irb_reference`,`researcher_id`,`witness_name`,`witness_relationship`,`notes`)
VALUES
  (1,1,NULL,'2026-01-10','interview','granted','verbal','DEMO-IRB-0001',1,'Demo Witness','field-assistant','Fictional consent record.'),
  (2,2,NULL,'2026-01-15','interview','granted','written','DEMO-IRB-0002',1,'Demo Witness','field-assistant','Fictional consent record.');

INSERT INTO `research_problem_rankings` (`id`,`interview_id`,`problem_number`,`problem_description`,`severity`,`category`) VALUES
  (1,1,1,'Fictional problem: water availability',3,'irrigation'),
  (2,1,2,'Fictional problem: pest control',2,'pest'),
  (3,3,1,'Fictional problem: market access',3,'market'),
  (4,3,2,'Fictional problem: input cost',2,'cost');

INSERT INTO `research_participant_responses` (`id`,`interview_id`,`question_group`,`question_key`,`response_value`) VALUES
  (1,1,'general','age_group','36-45'),
  (2,1,'technology','smartphone_use','frequent'),
  (3,3,'general','age_group','26-35'),
  (4,3,'technology','smartphone_use','rare');

SET FOREIGN_KEY_CHECKS = 1;
