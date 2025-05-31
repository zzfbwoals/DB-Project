-- [수강신청 삽입 트리거]: 수강 신청이 이루어질 때마다 해당 강좌의 수강 인원을 1 증가시키는 트리거입니다.
CREATE TRIGGER trg_after_insert_enroll
AFTER INSERT ON Enroll
FOR EACH ROW
BEGIN
    -- 수강 인원을 1 증가
    UPDATE Course
    SET currentEnrollment = currentEnrollment + 1
    WHERE courseID = NEW.courseID;
END;

-- [수강신청 삭제 트리거]: 수강 취소가 이루어질 때마다 해당 강좌의 수강 인원을 1 감소시키는 트리거입니다.
CREATE TRIGGER trg_after_delete_enroll
AFTER DELETE ON Enroll
FOR EACH ROW
BEGIN
    -- 수강 인원을 1 감소
    UPDATE Course
    SET currentEnrollment = currentEnrollment - 1
    WHERE courseID = OLD.courseID;
END;

-- [정원 초과 방지를 위한 트리거]: 수강 신청 시 해당 강좌의 현재 수강 인원이 정원을 초과하지 않도록 확인하는 트리거입니다.
DELIMITER $$
CREATE TRIGGER trg_before_insert_enroll_capacity
BEFORE INSERT ON Enroll
FOR EACH ROW
BEGIN
    -- 만약 세션 변수 @IGNORE_CAPACITY_CHECK 가 1 이면 검사 건너뛰기: 빌넣 요청 승인 시 사용
    IF @IGNORE_CAPACITY_CHECK IS NULL OR @IGNORE_CAPACITY_CHECK = 0 THEN
        DECLARE maxCap INT;
        DECLARE currCount INT;

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
    -- @IGNORE_CAPACITY_CHECK = 1 이면 capacity 체크 없이 그냥 INSERT 허용
END $$
$$
DELIMITER ;

-- [빌넣요청 삽입 트리거]: 빌넣요청이 이루어질 때마다 해당 강좌의 빌넣요청 수를 1 증가시키는 트리거입니다.
DELIMITER $$
CREATE PROCEDURE sp_approve_extra_enroll(
    IN p_extraEnrollID INT
)
BEGIN
    DECLARE v_userID  VARCHAR(20);
    DECLARE v_courseID VARCHAR(5);

    -- 1) ExtraEnroll 정보 읽기 (락 걸기)
    SELECT userID, courseID
      INTO v_userID, v_courseID
      FROM ExtraEnroll
     WHERE extraEnrollID = p_extraEnrollID
       FOR UPDATE;

    -- 2) capacity 초과 여부 상관없이 Enroll 추가 -> 트리거 건너뛰려면 세션 변수 설정
    SET @IGNORE_CAPACITY_CHECK = 1;  
    INSERT INTO Enroll(userID, courseID)
    VALUES(v_userID, v_courseID);
    SET @IGNORE_CAPACITY_CHECK = 0;  -- 다시 원래대로

    -- 3) ExtraEnroll 상태를 “승인”으로 변경
    UPDATE ExtraEnroll
       SET extraEnrollStatus = '승인'
     WHERE extraEnrollID = p_extraEnrollID;
END $$
$$
DELIMITER ;

-- [수강신청 충돌 검사 프로시저]: 수강 신청 시 시간표 충돌을 검사하고 충돌이 없으면 Enroll 테이블에 삽입합니다.
DELIMITER $$
CREATE PROCEDURE sp_enroll_with_conflict_check(
    IN p_userID VARCHAR(20),
    IN p_courseID VARCHAR(5)
)
BEGIN
    DECLARE v_conflictCount INT DEFAULT 0;

    -- 1) 신청하려는 강의 시간 정보 조회
    CREATE TEMPORARY TABLE tmp_new_times AS
    SELECT dayOfWeek, startPeriod, endPeriod
      FROM CourseTime
     WHERE courseID = p_courseID;

    -- 2) 이미 신청된 강의들의 시간 정보 조회
    CREATE TEMPORARY TABLE tmp_existing_times AS
    SELECT ct.dayOfWeek, ct.startPeriod, ct.endPeriod
      FROM Enroll e
      JOIN CourseTime ct ON e.courseID = ct.courseID
     WHERE e.userID = p_userID;

    -- 3) 충돌 검사: tmp_new_times와 tmp_existing_times 간 동일 요일에 시간 겹침
    SELECT COUNT(*) INTO v_conflictCount
      FROM tmp_new_times n
      JOIN tmp_existing_times e
        ON n.dayOfWeek = e.dayOfWeek
       AND NOT (n.endPeriod <= e.startPeriod OR n.startPeriod >= e.endPeriod);

    IF v_conflictCount > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '시간표 충돌로 인해 수강신청이 불가능합니다.';
    END IF;

    -- 4) 충돌 없으면 Enroll 삽입 (트리거로 currentEnrollment 증가)
    INSERT INTO Enroll(userID, courseID)
    VALUES(p_userID, p_courseID);

    -- 5) 임시 테이블 정리
    DROP TEMPORARY TABLE tmp_new_times;
    DROP TEMPORARY TABLE tmp_existing_times;
END $$
$$
DELIMITER ;
