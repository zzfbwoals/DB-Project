USE dbproject;

-- ===============================================
-- Helper Function: period_to_numeric
-- VARCHAR(5) 형식의 period를 소수로 변환
-- 예: '1' → 1.0, '1A' → 1.0, '1B' → 1.5, '2' → 2.0
-- ===============================================
DROP FUNCTION IF EXISTS period_to_numeric;
DELIMITER $$
CREATE FUNCTION period_to_numeric(p_period VARCHAR(5))
RETURNS FLOAT DETERMINISTIC
BEGIN
    DECLARE base_num INT;
    DECLARE suffix CHAR(1);
    DECLARE numeric_value FLOAT;

    -- 숫자 부분 추출
    SET base_num = CAST(REGEXP_REPLACE(p_period, '[A-B]', '') AS UNSIGNED);
    -- 접미사(A/B) 추출
    SET suffix = IF(p_period REGEXP '[A-B]', RIGHT(p_period, 1), '');

    -- 접미사에 따라 소수 변환
    IF suffix = 'B' THEN
        SET numeric_value = base_num + 0.5;
    ELSE
        SET numeric_value = base_num + 0.0;
    END IF;

    RETURN numeric_value;
END $$
DELIMITER ;

-- ===============================================
-- Trigger 1: Enroll 삽입 후 Course.currentEnrollment 증가
-- ===============================================
DROP TRIGGER IF EXISTS trg_after_insert_enroll;
DELIMITER $$
CREATE TRIGGER trg_after_insert_enroll
AFTER INSERT ON Enroll
FOR EACH ROW
BEGIN
    UPDATE Course
    SET currentEnrollment = currentEnrollment + 1
    WHERE courseID = NEW.courseID;
END $$
DELIMITER ;

-- ===============================================
-- Trigger 2: Enroll 삭제 후 Course.currentEnrollment 감소
-- ===============================================
DROP TRIGGER IF EXISTS trg_after_delete_enroll;
DELIMITER $$
CREATE TRIGGER trg_after_delete_enroll
AFTER DELETE ON Enroll
FOR EACH ROW
BEGIN
    UPDATE Course
    SET currentEnrollment = currentEnrollment - 1
    WHERE courseID = OLD.courseID;
END $$
DELIMITER ;

-- ===============================================
-- Trigger 3: Enroll 삽입 전 정원 초과 검사
-- @IGNORE_CAPACITY_CHECK = 1 시 검사 우회 (빌넣 승인용)
-- ===============================================
DROP TRIGGER IF EXISTS trg_before_insert_enroll_capacity;
DELIMITER $$
CREATE TRIGGER trg_before_insert_enroll_capacity
BEFORE INSERT ON Enroll
FOR EACH ROW
BEGIN
    DECLARE maxCap INT;
    DECLARE currCount INT;

    IF @IGNORE_CAPACITY_CHECK IS NULL OR @IGNORE_CAPACITY_CHECK = 0 THEN
        SELECT capacity, currentEnrollment
        INTO maxCap, currCount
        FROM Course
        WHERE courseID = NEW.courseID
        FOR UPDATE;

        IF currCount >= maxCap THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '정원이 초과되어 수강신청이 불가능합니다. 빌넣요청을 이용해주세요.';
        END IF;
    END IF;
END $$
DELIMITER ;

-- ===============================================
-- Procedure 1: 빌넣 요청 승인
-- ExtraEnroll 승인 시 Enroll 삽입 및 상태 업데이트
-- ===============================================
DROP PROCEDURE IF EXISTS sp_approve_extra_enroll;
DELIMITER $$
CREATE PROCEDURE sp_approve_extra_enroll(
    IN p_extraEnrollID INT
)
BEGIN
    DECLARE v_userID VARCHAR(20);
    DECLARE v_courseID VARCHAR(5);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '빌넣 요청 승인 중 오류가 발생했습니다.';
    END;

    START TRANSACTION;

    -- ExtraEnroll 정보 조회
    SELECT userID, courseID
    INTO v_userID, v_courseID
    FROM ExtraEnroll
    WHERE extraEnrollID = p_extraEnrollID
    FOR UPDATE;

    -- 정원 검사 우회
    SET @IGNORE_CAPACITY_CHECK = 1;
    INSERT INTO Enroll(userID, courseID)
    VALUES (v_userID, v_courseID);
    SET @IGNORE_CAPACITY_CHECK = 0;

    -- ExtraEnroll 상태 업데이트
    UPDATE ExtraEnroll
    SET extraEnrollStatus = '승인'
    WHERE extraEnrollID = p_extraEnrollID;

END $$
DELIMITER ;

-- ===============================================
-- Procedure 2: 수강신청 종합 검사 및 삽입
-- 검사: 과목 유효성, 중복 수강, 학점 초과, 시간표 충돌
-- ===============================================
DROP PROCEDURE IF EXISTS sp_enroll_with_conflict_check;
DELIMITER $$
CREATE PROCEDURE sp_enroll_with_conflict_check(
    IN p_userID VARCHAR(20),
    IN p_courseID VARCHAR(5)
)
BEGIN
    DECLARE v_existsCourse INT DEFAULT 0;
    DECLARE v_alreadyEnrolled INT DEFAULT 0;
    DECLARE v_lastSemCredits FLOAT DEFAULT 0;
    DECLARE v_currentCredits FLOAT DEFAULT 0;
    DECLARE v_newCourseCredits INT DEFAULT 0;
    DECLARE v_totalAfterEnroll FLOAT DEFAULT 0;
    DECLARE v_maxCreditsAllowed INT DEFAULT 18;
    DECLARE v_conflictCount INT DEFAULT 0;

    -- 과목코드 유효성 검사
    SELECT COUNT(*)
    INTO v_existsCourse
    FROM Course
    WHERE courseID = p_courseID;
    IF v_existsCourse = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '존재하지 않는 과목코드입니다.';
    END IF;

    -- 중복 수강신청 검사
    SELECT COUNT(*)
    INTO v_alreadyEnrolled
    FROM Enroll
    WHERE userID = p_userID
    AND courseID = p_courseID;
    IF v_alreadyEnrolled > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '이미 해당 과목을 수강신청하셨습니다.';
    END IF;

    -- 학점 초과 검사
    SELECT IFNULL(lastSemesterCredits, 0)
    INTO v_lastSemCredits
    FROM User
    WHERE userID = p_userID;

    IF v_lastSemCredits >= 3.0 THEN
        SET v_maxCreditsAllowed = 19;
    END IF;

    SELECT IFNULL(SUM(c.credits), 0)
    INTO v_currentCredits
    FROM Enroll e
    JOIN Course c ON e.courseID = c.courseID
    WHERE e.userID = p_userID;

    SELECT credits
    INTO v_newCourseCredits
    FROM Course
    WHERE courseID = p_courseID;

    SET v_totalAfterEnroll = v_currentCredits + v_newCourseCredits;
    IF v_totalAfterEnroll > v_maxCreditsAllowed THEN
        SET @error_msg = CONCAT(
            '최대 신청 가능 학점 ', v_maxCreditsAllowed,
            '을 초과했습니다. 현재: ', v_currentCredits,
            ', 추가: ', v_newCourseCredits
        );
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = @error_msg;
    END IF;

    -- 시간표 충돌 검사
    CREATE TEMPORARY TABLE tmp_new_times AS
    SELECT dayOfWeek, startPeriod, endPeriod,
           period_to_numeric(startPeriod) AS startNum,
           period_to_numeric(endPeriod) AS endNum
    FROM CourseTime
    WHERE courseID = p_courseID;

    CREATE TEMPORARY TABLE tmp_existing_times AS
    SELECT ct.dayOfWeek, ct.startPeriod, ct.endPeriod,
           period_to_numeric(ct.startPeriod) AS startNum,
           period_to_numeric(ct.endPeriod) AS endNum
    FROM Enroll e
    JOIN CourseTime ct ON e.courseID = ct.courseID
    WHERE e.userID = p_userID;

    SELECT COUNT(*)
    INTO v_conflictCount
    FROM tmp_new_times n
    JOIN tmp_existing_times e
    ON n.dayOfWeek = e.dayOfWeek
    AND NOT (n.endNum <= e.startNum OR n.startNum >= e.endNum);

    DROP TEMPORARY TABLE tmp_new_times;
    DROP TEMPORARY TABLE tmp_existing_times;

    IF v_conflictCount > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '시간표 충돌로 인해 수강신청이 불가능합니다.';
    END IF;

    -- Enroll 삽입
    INSERT INTO Enroll(userID, courseID)
    VALUES (p_userID, p_courseID);

END $$
DELIMITER ;

-- ===============================================
-- Procedure 3: 장바구니 추가 종합 검사 및 삽입
-- 검사: 과목 유효성, 수강/장바구니 중복, 시간표 충돌
-- ===============================================
DROP PROCEDURE IF EXISTS sp_cart_with_conflict_check;
DELIMITER $$
CREATE PROCEDURE sp_cart_with_conflict_check(
    IN p_userID VARCHAR(20),
    IN p_courseID VARCHAR(5)
)
BEGIN
    DECLARE v_existsCourse INT DEFAULT 0;
    DECLARE v_alreadyEnrolled INT DEFAULT 0;
	DECLARE v_alreadyInCart INT DEFAULT 0;
    DECLARE v_lastSemCredits FLOAT DEFAULT 0;
    DECLARE v_currentCredits FLOAT DEFAULT 0;
    DECLARE v_newCourseCredits INT DEFAULT 0;
    DECLARE v_totalAfterCart FLOAT DEFAULT 0;
    DECLARE v_maxCreditsAllowed INT DEFAULT 18;
    DECLARE v_conflictCount INT DEFAULT 0;

    -- 과목코드 유효성 검사
    SELECT COUNT(*)
    INTO v_existsCourse
    FROM Course
    WHERE courseID = p_courseID;
    IF v_existsCourse = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '존재하지 않는 과목코드입니다.';
    END IF;

    -- 수강신청 중복 검사
    SELECT COUNT(*)
    INTO v_alreadyEnrolled
    FROM Enroll
    WHERE userID = p_userID
    AND courseID = p_courseID;
    IF v_alreadyEnrolled > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '이미 수강신청한 과목입니다.';
    END IF;

    -- 장바구니 중복 검사
    SELECT COUNT(*)
    INTO v_alreadyInCart
    FROM Cart
    WHERE userID = p_userID
    AND courseID = p_courseID;
    IF v_alreadyInCart > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '이미 장바구니에 담긴 과목입니다.';
    END IF;

    -- 학점 초과 검사
    SELECT IFNULL(lastSemesterCredits, 0)
    INTO v_lastSemCredits
    FROM User
    WHERE userID = p_userID;

    IF v_lastSemCredits >= 3.0 THEN
        SET v_maxCreditsAllowed = 19;
    END IF;

    SELECT IFNULL(SUM(c.credits), 0)
    INTO v_currentCredits
    FROM Cart e
    JOIN Course c ON e.courseID = c.courseID
    WHERE e.userID = p_userID;

    SELECT credits
    INTO v_newCourseCredits
    FROM Course
    WHERE courseID = p_courseID;

    SET v_totalAfterCart = v_currentCredits + v_newCourseCredits;
    IF v_totalAfterCart > v_maxCreditsAllowed THEN
        SET @error_msg = CONCAT(
            '최대 신청 가능 학점 ', v_maxCreditsAllowed,
            '을 초과했습니다. 현재: ', v_currentCredits,
            ', 추가: ', v_newCourseCredits
        );
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = @error_msg;
    END IF;

    -- 시간표 충돌 검사
    CREATE TEMPORARY TABLE tmp_new_times AS
    SELECT dayOfWeek, startPeriod, endPeriod,
           period_to_numeric(startPeriod) AS startNum,
           period_to_numeric(endPeriod) AS endNum
    FROM CourseTime
    WHERE courseID = p_courseID;

    CREATE TEMPORARY TABLE tmp_existing_times AS
    SELECT ct.dayOfWeek, ct.startPeriod, ct.endPeriod,
           period_to_numeric(ct.startPeriod) AS startNum,
           period_to_numeric(ct.endPeriod) AS endNum
    FROM Enroll e
    JOIN CourseTime ct ON e.courseID = ct.courseID
    WHERE e.userID = p_userID
    UNION ALL
    SELECT ct2.dayOfWeek, ct2.startPeriod, ct2.endPeriod,
           period_to_numeric(ct2.startPeriod) AS startNum,
           period_to_numeric(ct2.endPeriod) AS endNum
    FROM Cart c
    JOIN CourseTime ct2 ON c.courseID = ct2.courseID
    WHERE c.userID = p_userID;

    SELECT COUNT(*)
    INTO v_conflictCount
    FROM tmp_new_times n
    JOIN tmp_existing_times e
    ON n.dayOfWeek = e.dayOfWeek
    AND NOT (n.endNum <= e.startNum OR n.startNum >= e.endNum);

    DROP TEMPORARY TABLE tmp_new_times;
    DROP TEMPORARY TABLE tmp_existing_times;

    IF v_conflictCount > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '시간표 충돌로 인해 예비 수강신청이 불가능합니다.';
    END IF;

    -- Cart 삽입
    INSERT INTO Cart(userID, courseID)
    VALUES (p_userID, p_courseID);

END $$
DELIMITER ;

-- ===============================================
-- Procedure 4: 빌넣 요청 종합 검사 및 삽입
-- 검사: 과목 유효성, 정원, 학점 초과, 시간표 충돌, 중복
-- ===============================================
DROP PROCEDURE IF EXISTS sp_extra_enroll_with_conflict_check;
DELIMITER $$
CREATE PROCEDURE sp_extra_enroll_with_conflict_check(
    IN p_userID VARCHAR(20),
    IN p_courseID VARCHAR(5),
    IN p_reason VARCHAR(255)
)
BEGIN
    DECLARE v_existsCourse INT DEFAULT 0;
    DECLARE v_capacity INT DEFAULT 0;
    DECLARE v_currentEnroll INT DEFAULT 0;
    DECLARE v_credits INT DEFAULT 0;
    DECLARE v_courseName VARCHAR(100);
    DECLARE v_lastSemCredits FLOAT DEFAULT 0;
    DECLARE v_maxCreditsAllowed INT DEFAULT 18;
    DECLARE v_currentCredits FLOAT DEFAULT 0;
    DECLARE v_extraCredits FLOAT DEFAULT 0;
    DECLARE v_totalCredits FLOAT DEFAULT 0;
    DECLARE v_conflictCount INT DEFAULT 0;
    DECLARE v_alreadyEnrolled INT DEFAULT 0;
    DECLARE v_alreadyRequested INT DEFAULT 0;

    -- 과목코드 유효성 및 정원 검사
    SELECT COUNT(*)
    INTO v_existsCourse
    FROM Course
    WHERE courseID = p_courseID;
    IF v_existsCourse = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '존재하지 않는 과목코드입니다.';
    END IF;

    SELECT capacity, currentEnrollment, credits, courseName
    INTO v_capacity, v_currentEnroll, v_credits, v_courseName
    FROM Course
    WHERE courseID = p_courseID
    FOR UPDATE;
    IF v_currentEnroll < v_capacity THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '정원이 아직 남아 있습니다. 수강신청 페이지에서 신청해주세요.';
    END IF;

    -- 학점 초과 검사
    SELECT IFNULL(lastSemesterCredits, 0)
    INTO v_lastSemCredits
    FROM User
    WHERE userID = p_userID;

    IF v_lastSemCredits >= 3.0 THEN
        SET v_maxCreditsAllowed = 19;
    END IF;

    SELECT IFNULL(SUM(c.credits), 0)
    INTO v_currentCredits
    FROM Enroll e
    JOIN Course c ON e.courseID = c.courseID
    WHERE e.userID = p_userID;

    SELECT IFNULL(SUM(c2.credits), 0)
    INTO v_extraCredits
    FROM ExtraEnroll ee
    JOIN Course c2 ON ee.courseID = c2.courseID
    WHERE ee.userID = p_userID
    AND ee.extraEnrollStatus = '대기';

    SET v_totalCredits = v_currentCredits + v_extraCredits + v_credits;
    IF v_totalCredits > v_maxCreditsAllowed THEN
        SET @error_msg = CONCAT(
            '신청 가능 학점 ', v_maxCreditsAllowed,
            '을 초과합니다. 현재: ', v_currentCredits,
            ', 대기: ', v_extraCredits,
            ', 요청: ', v_credits
        );
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = @error_msg;
    END IF;

    -- 시간표 충돌 검사
    CREATE TEMPORARY TABLE tmp_new_times AS
    SELECT dayOfWeek, startPeriod, endPeriod,
           period_to_numeric(startPeriod) AS startNum,
           period_to_numeric(endPeriod) AS endNum
    FROM CourseTime
    WHERE courseID = p_courseID;

    CREATE TEMPORARY TABLE tmp_existing_times AS
    SELECT ct.dayOfWeek, ct.startPeriod, ct.endPeriod,
           period_to_numeric(ct.startPeriod) AS startNum,
           period_to_numeric(ct.endPeriod) AS endNum
    FROM Enroll e
    JOIN CourseTime ct ON e.courseID = ct.courseID
    WHERE e.userID = p_userID
    UNION ALL
    SELECT ct2.dayOfWeek, ct2.startPeriod, ct2.endPeriod,
           period_to_numeric(ct2.startPeriod) AS startNum,
           period_to_numeric(ct2.endPeriod) AS endNum
    FROM ExtraEnroll ee
    JOIN CourseTime ct2 ON ee.courseID = ct2.courseID
    WHERE ee.userID = p_userID
    AND ee.extraEnrollStatus = '대기';

    SELECT COUNT(*)
    INTO v_conflictCount
    FROM tmp_new_times n
    JOIN tmp_existing_times e
    ON n.dayOfWeek = e.dayOfWeek
    AND NOT (n.endNum <= e.startNum OR n.startNum >= e.endNum);

    DROP TEMPORARY TABLE tmp_new_times;
    DROP TEMPORARY TABLE tmp_existing_times;

    IF v_conflictCount > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '시간표가 충돌합니다. 확인 후 다시 시도해주세요.';
    END IF;

    -- 수강신청 중복 검사
    SELECT COUNT(*)
    INTO v_alreadyEnrolled
    FROM Enroll
    WHERE userID = p_userID
    AND courseID = p_courseID;
    IF v_alreadyEnrolled > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '이미 수강신청한 과목입니다.';
    END IF;

    -- 빌넣 요청 중복 검사
    SELECT COUNT(*)
    INTO v_alreadyRequested
    FROM ExtraEnroll
    WHERE userID = p_userID
    AND courseID = p_courseID
    AND extraEnrollStatus = '대기';
    IF v_alreadyRequested > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '이미 빌넣요청한 과목입니다.';
    END IF;

    -- ExtraEnroll 삽입
    INSERT INTO ExtraEnroll (userID, courseID, reason, extraEnrollStatus)
    VALUES (p_userID, p_courseID, p_reason, '대기');

END $$
DELIMITER ;