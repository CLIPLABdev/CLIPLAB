ALTER TABLE clip_render_profiles
    DROP CONSTRAINT chk_clip_render_profiles_shape,
    ADD CONSTRAINT chk_clip_render_profiles_shape CHECK (
        (aspect_ratio = 'original' AND reframe_mode = 'original' AND output_width IS NULL AND output_height IS NULL)
        OR (reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND (
            (aspect_ratio = '9:16' AND ((output_width = 720 AND output_height = 1280) OR (output_width = 1080 AND output_height = 1920)))
            OR (aspect_ratio = '1:1' AND ((output_width = 720 AND output_height = 720) OR (output_width = 1080 AND output_height = 1080)))
            OR (aspect_ratio = '16:9' AND ((output_width = 1280 AND output_height = 720) OR (output_width = 1920 AND output_height = 1080)))
            OR (aspect_ratio = '4:5' AND ((output_width = 720 AND output_height = 900) OR (output_width = 1080 AND output_height = 1350)))
        ))
    );
