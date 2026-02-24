-- Migration: add contact_name and contact_phone to reminders
ALTER TABLE `reminders`
  ADD COLUMN `contact_name` VARCHAR(255) NULL AFTER `template_id`,
  ADD COLUMN `contact_phone` VARCHAR(50) NULL AFTER `contact_name`;

-- You can run this with your DB tool (phpMyAdmin or mysql CLI):
-- mysql -u user -p crm < database/add_contact_fields_to_reminders.sql
