USE dbproject;

-- ===============================================
-- Helper Function: period_to_numeric
-- 목적: 'period' 값을 숫자로 변환하여 시간표 비교를 용이하게 함
-- 입력: VARCHAR(5) 형식의 period (예: '1', '1A', '1B', '2')
-- 출력: FLOAT 형식의 숫자 값
-- 변환 규칙:
--   - '1' → 1.0 (접미사 없음, 정수 값 그대로)
--   - '1A' → 1.0 ('A' 접미사는 기본값, 추가 없음)
--   - '1B' → 1.5 ('B' 접미사는 0.5 추가)
--   - '2' → 2.0
-- 특성: DETERMINISTIC (동일 입력에 대해 항상 동일 결과 반환)
-- ===============================================
DROP FUNCTION IF EXISTS period_to_numeric;
DELIMITER $$
CREATE FUNCTION period_to_numeric(p_period VARCHAR(5))
RETURNS FLOAT DETERMINISTIC
BEGIN
    DECLARE base_num INT;        -- period의 숫자 부분을 저장
    DECLARE suffix CHAR(1);      -- period의 접미사(A 또는 B)를 저장
    DECLARE numeric_value FLOAT;  -- 최종 변환된 숫자 값을 저장

    -- 숫자 부분 추출: 문자(A, B)를 제거하고 정수로 변환
    SET base_num = CAST(REGEXP_REPLACE(p_period, '[A-B]', '') AS UNSIGNED);
    -- 접미사 추출: period에 A 또는 B가 있으면 마지막 문자를, 없으면 빈 문자열을 저장
    SET suffix = IF(p_period REGEXP '[A-B]', RIGHT(p_period, 1), '');

    -- 접미사에 따라 값 변환
    IF suffix = 'B' THEN
        -- 'B' 접미사: 0.5를 더해 반 수업 단위를 표현 (예: '1B' → 1.5)
        SET numeric_value = base_num + 0.5;
    ELSE
        -- 접미사 없음 또는 'A': 기본 숫자 값 유지 (예: '1' → 1.0, '1A' → 1.0)
        SET numeric_value = base_num + 0.0;
    END IF;

    -- 변환된 숫자 값 반환
    RETURN numeric_value;
END $$
DELIMITER ;

-- ===============================================
-- Trigger 1: trg_after_insert_enroll
-- 목적: Enroll 테이블에 수강신청 레코드 삽입 후 Course 테이블의 현재 수강 인원 업데이트
-- 트리거 시점: Enroll 테이블에 INSERT 작업 후 실행
-- 동작:
--   1. 새로 삽입된 레코드(NEW)의 courseID를 참조
--   2. Course 테이블에서 해당 courseID의 currentEnrollment 값을 1 증가
-- 사용 예: 학생이 과목 'CS101'을 수강신청하면 Course.currentEnrollment 증가
-- ===============================================
DROP TRIGGER IF EXISTS trg_after_insert_enroll;
DELIMITER $$
CREATE TRIGGER trg_after_insert_enroll
AFTER INSERT ON Enroll
FOR EACH ROW
BEGIN
    -- Course 테이블 업데이트: 현재 수강 인원 1 증가
    UPDATE Course
    SET currentEnrollment = currentEnrollment + 1
    WHERE courseID = NEW.courseID;
END $$
DELIMITER ;

-- ===============================================
-- Trigger 2: trg_after_delete_enroll
-- 목적: Enroll 테이블에서 수강신청 레코드 삭제 후 Course 테이블의 현재 수강 인원 감소
-- 트리거 시점: Enroll 테이블에서 DELETE 작업 후 실행
-- 동작:
--   1. 삭제된 레코드(OLD)의 courseID를 참조
--   2. Course 테이블에서 해당 courseID의 currentEnrollment 값을 1 감소
-- 사용 예: 학생이 과목 'CS101' 수강을 취소하면 Course.currentEnrollment 감소
-- ===============================================
DROP TRIGGER IF EXISTS trg_after_delete_enroll;
DELIMITER $$
CREATE TRIGGER trg_after_delete_enroll
AFTER DELETE ON Enroll
FOR EACH ROW
BEGIN
    -- Course 테이블 업데이트: 현재 수강 인원 1 감소
    UPDATE Course
    SET currentEnrollment = currentEnrollment - 1
    WHERE courseID = OLD.courseID;
END $$
DELIMITER ;

-- ===============================================
-- Trigger 3: trg_before_insert_enroll_capacity
-- 목적: Enroll 테이블에 수강신청 레코드 삽입 전 정원 초과 여부 검사
-- 트리거 시점: Enroll 테이블에 INSERT 작업 전 실행
-- 동작:
--   1. @IGNORE_CAPACITY_CHECK 변수 확인 (1이면 정원 검사 우회)
--   2. Course 테이블에서 courseID에 해당하는 capacity와 currentEnrollment 조회
--   3. 현재 수강 인원(currCount)이 정원(maxCap)을 초과하면 에러 발생
-- 변수:
--   - maxCap: 과목의 최대 정원
--   - currCount: 현재 수강 인원
-- 예외: @IGNORE_CAPACITY_CHECK = 1 시 정원 검사를 건너뛰고 삽입 허용 (빌넣 요청용)
-- 에러: 정원 초과 시 SQLSTATE '45000'과 메시지 발생
-- 사용 예: 'CS101' 정원이 30명이고 30명 신청 시, 추가 신청 차단
-- ===============================================
DROP TRIGGER IF EXISTS trg_before_insert_enroll_capacity;
DELIMITER $$
CREATE TRIGGER trg_before_insert_enroll_capacity
BEFORE INSERT ON Enroll
FOR EACH ROW
BEGIN
    DECLARE maxCap INT;  -- 과목의 최대 정원 저장
    DECLARE currCount INT;  -- 현재 수강 인원 저장

    -- 정원 검사 우회 조건: @IGNORE_CAPACITY_CHECK가 NULL 또는 0일 때만 검사
    IF @IGNORE_CAPACITY_CHECK IS NULL OR @IGNORE_CAPACITY_CHECK = 0 THEN
        -- Course 테이블에서 정원과 현재 수강 인원 조회 (동시성 제어를 위해 FOR UPDATE 사용)
        SELECT capacity, currentEnrollment
        INTO maxCap, currCount
        FROM Course
        WHERE courseID = NEW.courseID
        FOR UPDATE;

        -- 현재 인원이 정원을 초과하면 에러 발생
        IF currCount >= maxCap THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '정원이 가득 찼습니다. 빌넣 요청을 이용해주세요.';
        END IF;
    END IF;
END $$
DELIMITER ;

-- ===============================================
-- Procedure 1: sp_approve_extra_enroll
-- 목적: ExtraEnroll 테이블의 빌넣 요청을 승인하고 Enroll에 레코드 삽입
-- 입력: p_extraEnrollID (승인할 빌넣 요청 ID)
-- 동작:
--   1. ExtraEnroll에서 userID와 courseID 조회
--   2. 정원 검사 우회 변수(@IGNORE_CAPACITY_CHECK)를 1로 설정
--   3. Enroll 테이블에 수강신청 레코드 삽입
--   4. ExtraEnroll 상태를 '승인'으로 업데이트
-- 트랜잭션: 오류 시 롤백 보장
-- 예외 처리: SQLEXCEPTION 발생 시 롤백 후 에러 메시지 출력
-- 사용 예: extraEnrollID 1번 요청 승인 시, 학생을 과목에 등록
-- ===============================================
DROP PROCEDURE IF EXISTS sp_approve_extra_enroll;
DELIMITER $$
CREATE PROCEDURE sp_approve_extra_enroll(
    IN p_extraEnrollID INT
)
BEGIN
    DECLARE v_userID VARCHAR(20);  -- 빌넣 요청의 사용자 ID
    DECLARE v_courseID VARCHAR(5);  -- 빌넣 요청의 과목 코드

    -- 예외 처리: 오류 발생 시 롤백 및 에러 메시지 출력
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '빌넣 요청 승인 중 오류가 발생했습니다.';
    END;

    -- 트랜잭션 시작: 데이터 일관성 보장
    START TRANSACTION;

    -- ExtraEnroll 테이블에서 요청 정보 조회 (동시성 제어를 위해 FOR UPDATE)
    SELECT userID, courseID
    INTO v_userID, v_courseID
    FROM ExtraEnroll
    WHERE extraEnrollID = p_extraEnrollID
    FOR UPDATE;

    -- 정원 검사 우회 설정
    SET @IGNORE_CAPACITY_CHECK = 1;
    -- Enroll 테이블에 수강신청 레코드 삽입
    INSERT INTO Enroll(userID, courseID)
    VALUES (v_userID, v_courseID);
    -- 우회 설정 초기화
    SET @IGNORE_CAPACITY_CHECK = 0;

    -- ExtraEnroll 상태를 '승인'으로 업데이트
    UPDATE ExtraEnroll
    SET extraEnrollStatus = '승인'
    WHERE extraEnrollID = p_extraEnrollID;

END $$
DELIMITER ;

-- ===============================================
-- Procedure 2: sp_enroll_with_conflict_check
-- 목적: 수강신청 전 다양한 조건을 검사하고 Enroll 테이블에 삽입
-- 입력:
--   - p_userID: 수강신청을 하는 학생의 ID
--   - p_courseID: 신청하려는 과목 코드
-- 검사 항목:
--   1. 과목 유효성: Course 테이블에 과목 존재 여부 확인
--   2. 중복 수강: 이미 Enroll에 동일 과목 신청 여부 확인
--   3. 학점 초과: 이전 학기 성적(lastSemesterCredits)에 따라 최대 학점(18 or 19) 제한
--   4. 시간표 충돌: 신청 과목과 기존 수강 과목의 시간표 비교
-- 동작:
--   - 모든 검사 통과 시 Enroll 테이블에 레코드 삽입
-- 변수:
--   - v_maxCreditsAllowed: 최대 신청 가능 학점 (기본 18, lastSemesterCredits >= 3.0 시 19)
--   - v_conflictCount: 시간표 충돌 건수
-- 에러: 각 검사 실패 시 SQLSTATE '45000'과 구체적 메시지 발생
-- 사용 예: 학생 'S001'이 'CS101'을 신청 시, 조건 검사 후 등록
-- ===============================================
DROP PROCEDURE IF EXISTS sp_enroll_with_conflict_check;
DELIMITER $$
CREATE PROCEDURE sp_enroll_with_conflict_check(
    IN p_userID VARCHAR(20),
    IN p_courseID VARCHAR(5)
)
BEGIN
    DECLARE v_existsCourse INT DEFAULT 0;          -- 과목 존재 여부
    DECLARE v_alreadyEnrolled INT DEFAULT 0;       -- 중복 수강 여부
    DECLARE v_lastSemCredits FLOAT DEFAULT 0;      -- 이전 학기 평점
    DECLARE v_currentCredits FLOAT DEFAULT 0;      -- 현재 신청 학점
    DECLARE v_newCourseCredits INT DEFAULT 0;      -- 신청 과목 학점
    DECLARE v_totalAfterEnroll FLOAT DEFAULT 0;    -- 신청 후 총 학점
    DECLARE v_maxCreditsAllowed INT DEFAULT 18;    -- 최대 신청 가능 학점
    DECLARE v_conflictCount INT DEFAULT 0;         -- 시간표 충돌 건수

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
    -- 이전 학기 평점 조회
    SELECT IFNULL(lastSemesterCredits, 0)
    INTO v_lastSemCredits
    FROM User
    WHERE userID = p_userID;

    -- 평점 3.0 이상 시 최대 학점 19로 조정
    IF v_lastSemCredits >= 3.0 THEN
        SET v_maxCreditsAllowed = 19;
    END IF;

    -- 현재 수강 중인 과목의 총 학점 계산
    SELECT IFNULL(SUM(c.credits), 0)
    INTO v_currentCredits
    FROM Enroll e
    JOIN Course c ON e.courseID = c.courseID
    WHERE e.userID = p_userID;

    -- 신청 과목의 학점 조회
    SELECT credits
    INTO v_newCourseCredits
    FROM Course
    WHERE courseID = p_courseID;

    -- 신청 후 총 학점 계산
    SET v_totalAfterEnroll = v_currentCredits + v_newCourseCredits;
    IF v_totalAfterEnroll > v_maxCreditsAllowed THEN
        SET @error_msg = CONCAT(
            '최대 신청 가능 학점을 초과했습니다. (최대: ', v_maxCreditsAllowed,
            ' 학점, 현재: ', v_currentCredits,
            ' 학점, 추가 시도: ', v_newCourseCredits, ' 학점)'
        );
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = @error_msg;
    END IF;

    -- 시간표 충돌 검사
    -- 신청 과목의 시간표를 임시 테이블에 저장
    CREATE TEMPORARY TABLE tmp_new_times AS
    SELECT dayOfWeek, startPeriod, endPeriod,
           period_to_numeric(startPeriod) AS startNum,
           period_to_numeric(endPeriod) AS endNum
    FROM CourseTime
    WHERE courseID = p_courseID;

    -- 기존 수강 과목의 시간표를 임시 테이블에 저장
    CREATE TEMPORARY TABLE tmp_existing_times AS
    SELECT ct.dayOfWeek, ct.startPeriod, ct.endPeriod,
           period_to_numeric(ct.startPeriod) AS startNum,
           period_to_numeric(ct.endPeriod) AS endNum
    FROM Enroll e
    JOIN CourseTime ct ON e.courseID = ct.courseID
    WHERE e.userID = p_userID;

    -- 시간표 충돌 여부 확인: 동일 요일에서 시간대가 겹치는 경우 계산
    SELECT COUNT(*)
    INTO v_conflictCount
    FROM tmp_new_times n
    JOIN tmp_existing_times e
    ON n.dayOfWeek = e.dayOfWeek
    AND NOT (n.endNum <= e.startNum OR n.startNum >= e.endNum);

    -- 임시 테이블 삭제
    DROP TEMPORARY TABLE tmp_new_times;
    DROP TEMPORARY TABLE tmp_existing_times;

    -- 충돌 존재 시 에러 발생
    IF v_conflictCount > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '시간표가 충돌합니다. 다른 강의를 선택해주세요.';
    END IF;

    -- 모든 검사 통과 시 Enroll 테이블에 레코드 삽입
    INSERT INTO Enroll(userID, courseID)
    VALUES (p_userID, p_courseID);

END $$
DELIMITER ;

-- ===============================================
-- Procedure 3: sp_cart_with_conflict_check
-- 목적: 장바구니 추가 전 조건 검사 후 Cart 테이블에 삽입
-- 입력:
--   - p_userID: 장바구니에 추가하려는 학생의 ID
--   - p_courseID: 장바구니에 추가할 과목 코드
-- 검사 항목:
--   1. 과목 유효성: Course 테이블에 과목 존재 여부 확인
--   2. 수강 중복: Enroll 테이블에 이미 신청 여부 확인
--   3. 장바구니 중복: Cart 테이블에 이미 존재 여부 확인
--   4. 학점 초과: 최대 학점(18 or 19) 초과 여부 확인
--   5. 시간표 충돌: 신청/장바구니 과목과 시간대 비교
-- 변수:
--   - v_maxCreditsAllowed: 최대 신청 가능 학점 (기본 18, lastSemesterCredits >= 3.0 시 19)
--   - v_conflictCount: 시간표 충돌 건수
-- 에러: 각 검사 실패 시 SQLSTATE '45000'과 구체적 메시지 발생
-- 사용 예: 학생 'S001'이 'CS101'을 장바구니에 추가
-- ===============================================
DROP PROCEDURE IF EXISTS sp_cart_with_conflict_check;
DELIMITER $$
CREATE PROCEDURE sp_cart_with_conflict_check(
    IN p_userID VARCHAR(20),
    IN p_courseID VARCHAR(5)
)
BEGIN
    DECLARE v_existsCourse INT DEFAULT 0;          -- 과목 존재 여부
    DECLARE v_alreadyEnrolled INT DEFAULT 0;       -- 수강신청 중복 여부
    DECLARE v_alreadyInCart INT DEFAULT 0;         -- 장바구니 중복 여부
    DECLARE v_lastSemCredits FLOAT DEFAULT 0;      -- 이전 학기 평점
    DECLARE v_currentCredits FLOAT DEFAULT 0;      -- 현재 장바구니 학점
    DECLARE v_newCourseCredits INT DEFAULT 0;      -- 추가 과목 학점
    DECLARE v_totalAfterCart FLOAT DEFAULT 0;      -- 추가 후 총 학점
    DECLARE v_maxCreditsAllowed INT DEFAULT 18;    -- 최대 신청 가능 학점
    DECLARE v_conflictCount INT DEFAULT 0;         -- 시간표 충돌 건수

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
        SET MESSAGE_TEXT = '이미 장바구니에 추가된 과목입니다.';
    END IF;

    -- 학점 초과 검사
    -- 이전 학기 평점 조회
    SELECT IFNULL(lastSemesterCredits, 0)
    INTO v_lastSemCredits
    FROM User
    WHERE userID = p_userID;

    -- 평점 3.0 이상 시 최대 학점 19로 조정
    IF v_lastSemCredits >= 3.0 THEN
        SET v_maxCreditsAllowed = 19;
    END IF;

    -- 현재 장바구니의 총 학점 계산
    SELECT IFNULL(SUM(c.credits), 0)
    INTO v_currentCredits
    FROM Cart e
    JOIN Course c ON e.courseID = c.courseID
    WHERE e.userID = p_userID;

    -- 추가 과목의 학점 조회
    SELECT credits
    INTO v_newCourseCredits
    FROM Course
    WHERE courseID = p_courseID;

    -- 추가 후 총 학점 계산
    SET v_totalAfterCart = v_currentCredits + v_newCourseCredits;
    IF v_totalAfterCart > v_maxCreditsAllowed THEN
        SET @error_msg = CONCAT(
            '최대 신청 가능 학점을 초과했습니다. (최대: ', v_maxCreditsAllowed,
            ' 학점, 현재: ', v_currentCredits,
            ' 학점, 추가 시도: ', v_newCourseCredits, ' 학점)'
        );
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = @error_msg;
    END IF;

    -- 시간표 충돌 검사
    -- 신청 과목의 시간표를 임시 테이블에 저장
    CREATE TEMPORARY TABLE tmp_new_times AS
    SELECT dayOfWeek, startPeriod, endPeriod,
           period_to_numeric(startPeriod) AS startNum,
           period_to_numeric(endPeriod) AS endNum
    FROM CourseTime
    WHERE courseID = p_courseID;

    -- 기존 수강신청 및 장바구니 과목의 시간표를 임시 테이블에 저장
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

    -- 시간표 충돌 여부 확인: 동일 요일에서 시간대 겹침 계산
    SELECT COUNT(*)
    INTO v_conflictCount
    FROM tmp_new_times n
    JOIN tmp_existing_times e
    ON n.dayOfWeek = e.dayOfWeek
    AND NOT (n.endNum <= e.startNum OR n.startNum >= e.endNum);

    -- 임시 테이블 삭제
    DROP TEMPORARY TABLE tmp_new_times;
    DROP TEMPORARY TABLE tmp_existing_times;

    -- 충돌 존재 시 에러 발생
    IF v_conflictCount > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '시간표 충돌로 인해 예비 수강신청이 불가능합니다.';
    END IF;

    -- 모든 검사 통과 시 Cart 테이블에 레코드 삽입
    INSERT INTO Cart(userID, courseID)
    VALUES (p_userID, p_courseID);

END $$
DELIMITER ;

-- ===============================================
-- Procedure 4: sp_extra_enroll_with_conflict_check
-- 목적: 빌넣 요청 전 조건 검사 후 ExtraEnroll 테이블에 삽입
-- 입력:
--   - p_userID: 빌넣 요청을 하는 학생의 ID
--   - p_courseID: 요청할 과목 코드
--   - p_reason: 빌넣 요청 사유
-- 검사 항목:
--   1. 과목 유효성: Course 테이블에 과목 존재 여부 확인
--   2. 정원: 현재 인원이 정원을 초과했는지 확인
--   3. 학점 초과: 최대 학점(18 or 19) 초과 여부 확인
--   4. 시간표 충돌: 기존 수강/대기 과목과 시간대 비교
--   5. 중복: 수강신청 또는 대기 중인 빌넣 요청 여부 확인
-- 동작:
--   - 모든 검사 통과 시 ExtraEnroll 테이블에 '대기' 상태로 삽입
-- 변수:
--   - v_maxCreditsAllowed: 최대 신청 가능 학점 (기본 18, lastSemesterCredits >= 3.0 시 19)
--   - v_conflictCount: 시간표 충돌 건수
-- 에러: 각 검사 실패 시 SQLSTATE '45000'과 구체적 메시지 발생
-- 사용 예: 학생 'S001'이 'CS101'에 빌넣 요청, 사유 '졸업 필수'
-- ===============================================
DROP PROCEDURE IF EXISTS sp_extra_enroll_with_conflict_check;
DELIMITER $$
CREATE PROCEDURE sp_extra_enroll_with_conflict_check(
    IN p_userID VARCHAR(20),
    IN p_courseID VARCHAR(5),
    IN p_reason VARCHAR(255)
)
BEGIN
    DECLARE v_existsCourse INT DEFAULT 0;          -- 과목 존재 여부
    DECLARE v_capacity INT DEFAULT 0;              -- 과목 정원
    DECLARE v_currentEnroll INT DEFAULT 0;         -- 현재 수강 인원
    DECLARE v_newCourseCredits INT DEFAULT 0;               -- 요청 과목 학점
    DECLARE v_courseName VARCHAR(100);             -- 과목명
    DECLARE v_lastSemCredits FLOAT DEFAULT 0;      -- 이전 학기 평점
    DECLARE v_maxCreditsAllowed INT DEFAULT 18;    -- 최대 신청 가능 학점
    DECLARE v_currentCredits FLOAT DEFAULT 0;      -- 현재 수강 학점
    DECLARE v_extraCredits FLOAT DEFAULT 0;        -- 대기 중인 빌넣 학점
    DECLARE v_totalCredits FLOAT DEFAULT 0;        -- 요청 후 총 학점
    DECLARE v_conflictCount INT DEFAULT 0;         -- 시간표 충돌 건수
    DECLARE v_alreadyEnrolled INT DEFAULT 0;       -- 수강신청 중복 여부
    DECLARE v_alreadyRequested INT DEFAULT 0;      -- 빌넣 요청 중복 여부

    -- 과목코드 유효성 및 정원 검사
    SELECT COUNT(*)
    INTO v_existsCourse
    FROM Course
    WHERE courseID = p_courseID;
    IF v_existsCourse = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '존재하지 않는 과목코드입니다.';
    END IF;

    -- 정원 및 과목 정보 조회 (동시성 제어를 위해 FOR UPDATE)
    SELECT capacity, currentEnrollment, credits, courseName
    INTO v_capacity, v_currentEnroll, v_newCourseCredits, v_courseName
    FROM Course
    WHERE courseID = p_courseID
    FOR UPDATE;
    -- 정원이 남아 있으면 빌넣 불필요
    IF v_currentEnroll < v_capacity THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '정원이 아직 남아 있습니다. 수강신청 페이지에서 신청해주세요.';
    END IF;

    -- 학점 초과 검사
    -- 이전 학기 평점 조회
    SELECT IFNULL(lastSemesterCredits, 0)
    INTO v_lastSemCredits
    FROM User
    WHERE userID = p_userID;

    -- 평점 3.0 이상 시 최대 학점 19로 조정
    IF v_lastSemCredits >= 3.0 THEN
        SET v_maxCreditsAllowed = 19;
    END IF;

    -- 현재 수강 중인 과목의 총 학점 계산
    SELECT IFNULL(SUM(c.credits), 0)
    INTO v_currentCredits
    FROM Enroll e
    JOIN Course c ON e.courseID = c.courseID
    WHERE e.userID = p_userID;

    -- 대기 중인 빌넣 요청의 총 학점 계산
    SELECT IFNULL(SUM(c2.credits), 0)
    INTO v_extraCredits
    FROM ExtraEnroll ee
    JOIN Course c2 ON ee.courseID = c2.courseID
    WHERE ee.userID = p_userID
    AND ee.extraEnrollStatus = '대기';

    -- 요청 후 총 학점 계산
    SET v_totalCredits = v_currentCredits + v_extraCredits + v_newCourseCredits;
    IF v_totalCredits > v_maxCreditsAllowed THEN
        SET @error_msg = CONCAT(
            '최대 신청 가능 학점을 초과했습니다. (최대: ', v_maxCreditsAllowed,
            ' 학점, 현재: ', v_currentCredits,
            ' 학점, 추가 시도: ', v_newCourseCredits, ' 학점)'
        );
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = @error_msg;
    END IF;

    -- 시간표 충돌 검사
    -- 요청 과목의 시간표를 임시 테이블에 저장
    CREATE TEMPORARY TABLE tmp_new_times AS
    SELECT dayOfWeek, startPeriod, endPeriod,
           period_to_numeric(startPeriod) AS startNum,
           period_to_numeric(endPeriod) AS endNum
    FROM CourseTime
    WHERE courseID = p_courseID;

    -- 기존 수강 및 대기 과목의 시간표를 임시 테이블에 저장
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

    -- 시간표 충돌 여부 확인: 동일 요일에서 시간대 겹침 계산
    SELECT COUNT(*)
    INTO v_conflictCount
    FROM tmp_new_times n
    JOIN tmp_existing_times e
    ON n.dayOfWeek = e.dayOfWeek
    AND NOT (n.endNum <= e.startNum OR n.startNum >= e.endNum);

    -- 임시 테이블 삭제
    DROP TEMPORARY TABLE tmp_new_times;
    DROP TEMPORARY TABLE tmp_existing_times;

    -- 충돌 존재 시 에러 발생
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

    -- 모든 검사 통과 시 ExtraEnroll 테이블에 '대기' 상태로 삽입
    INSERT INTO ExtraEnroll (userID, courseID, reason, extraEnrollStatus)
    VALUES (p_userID, p_courseID, p_reason, '대기');

END $$
DELIMITER ;