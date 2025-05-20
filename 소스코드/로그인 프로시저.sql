USE dbproject;

DELIMITER //

CREATE PROCEDURE loginUser(
    IN input_id VARCHAR(50),
    IN input_pw VARCHAR(50),
    OUT success INT
)
BEGIN
    DECLARE cnt INT;

    SELECT COUNT(*) INTO cnt
    FROM User
    WHERE userID = input_id AND userPassword = input_pw;

    IF cnt > 0 THEN
        SET success = 1;  -- 로그인 성공
    ELSE
        SET success = 0;  -- 로그인 실패
    END IF;
END //

DELIMITER ;