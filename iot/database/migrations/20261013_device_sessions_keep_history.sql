-- Let a device code be deleted without losing results.
-- device_sessions rows record which student took part in a session; the
-- device that student used is only needed while the session runs. When a
-- device is deleted, its past rows keep the student and lose only the device.
ALTER TABLE device_sessions
    DROP FOREIGN KEY fk_device_sessions_device;

ALTER TABLE device_sessions
    MODIFY device_id INT(11) NULL;

ALTER TABLE device_sessions
    ADD CONSTRAINT fk_device_sessions_device
        FOREIGN KEY (device_id) REFERENCES devices (device_id)
        ON DELETE SET NULL ON UPDATE RESTRICT;
