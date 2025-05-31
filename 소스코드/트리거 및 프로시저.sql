USE dbproject;

-- ===============================================
-- 1) 수강신청 삽입 트리거
--    Enroll 삽입 시 Course.currentEnrollment을 +1 해 주는 트리거
-- ===============================================
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
-- 2) 수강신청 삭제 트리거
--    Enroll 삭제 시 Course.currentEnrollment을 -1 해 주는 트리거
-- ===============================================
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
-- 3) 정원 초과 방지를 위한 트리거
--    Enroll 삽입 전 currentEnrollment >= capacity 면 에러 발생
--    세션 변수 @IGNORE_CAPACITY_CHECK = 1 이면 검사 건너뛰기
-- ===============================================
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
         FOR UPDATE;  -- 동시성 락

        IF currCount >= maxCap THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = '정원이 초과되어 수강신청이 불가능합니다.';
        END IF;
    END IF;
    -- @IGNORE_CAPACITY_CHECK = 1 이면 capacity 체크 없이 그냥 INSERT 허용: 빌넣 요청 승인 시 사용
END $$
DELIMITER ;


-- ===============================================
-- 4) 빌넣 요청 승인 프로시저
--    ExtraEnroll 승인 시 Enroll에 삽입하고 상태 업데이트
--    (이때 트리거의 정원 검사만 우회하기 위해 세션 변수 사용)
-- ===============================================
DELIMITER $$
CREATE PROCEDURE sp_approve_extra_enroll(
    IN p_extraEnrollID INT
)
BEGIN
    DECLARE v_userID  VARCHAR(20);
    DECLARE v_courseID VARCHAR(5);

    -- 1) ExtraEnroll 정보 조회 (락 걸기)
    SELECT userID, courseID
      INTO v_userID, v_courseID
      FROM ExtraEnroll
     WHERE extraEnrollID = p_extraEnrollID
       FOR UPDATE;

    -- 2) 정원 검사 우회: @IGNORE_CAPACITY_CHECK를 1로 세팅
    SET @IGNORE_CAPACITY_CHECK = 1;
    INSERT INTO Enroll(userID, courseID)
    VALUES(v_userID, v_courseID);
    -- 다시 원상태로 돌려놓기
    SET @IGNORE_CAPACITY_CHECK = 0;

    -- 3) ExtraEnroll 상태를 '승인'으로 변경
    UPDATE ExtraEnroll
       SET extraEnrollStatus = '승인'
     WHERE extraEnrollID = p_extraEnrollID;
END $$
DELIMITER ;


-- ===============================================
-- 5) 수강신청 충돌 검사 + 삽입 프로시저
--    시간표 충돌이 없을 경우에만 Enroll에 삽입
-- ===============================================
DELIMITER $$
CREATE PROCEDURE sp_enroll_with_conflict_check(
    IN p_userID VARCHAR(20),
    IN p_courseID VARCHAR(5)
)
BEGIN
    DECLARE v_conflictCount INT DEFAULT 0;

    -- (1) 신규 강의 시간 정보 임시 테이블에 저장
    CREATE TEMPORARY TABLE tmp_new_times AS
    SELECT dayOfWeek, startPeriod, endPeriod
      FROM CourseTime
     WHERE courseID = p_courseID;

    -- (2) 이미 신청된 강의들의 시간 정보 임시 테이블에 저장
    CREATE TEMPORARY TABLE tmp_existing_times AS
    SELECT ct.dayOfWeek, ct.startPeriod, ct.endPeriod
      FROM Enroll e
      JOIN CourseTime ct ON e.courseID = ct.courseID
     WHERE e.userID = p_userID;

    -- (3) 충돌 검사: 동일 요일에 시간 겹치는 경우 개수 확인
    SELECT COUNT(*) INTO v_conflictCount
      FROM tmp_new_times n
      JOIN tmp_existing_times e
        ON n.dayOfWeek = e.dayOfWeek
       AND NOT (n.endPeriod <= e.startPeriod 
             OR n.startPeriod >= e.endPeriod);

    IF v_conflictCount > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '시간표 충돌로 인해 수강신청이 불가능합니다.';
    END IF;

    -- (4) 충돌 없으면 Enroll 삽입 (트리거로 currentEnrollment 관리됨)
    INSERT INTO Enroll(userID, courseID)
    VALUES(p_userID, p_courseID);

    -- (5) 임시 테이블 정리
    DROP TEMPORARY TABLE IF EXISTS tmp_new_times;
    DROP TEMPORARY TABLE IF EXISTS tmp_existing_times;
END $$
DELIMITER ;
