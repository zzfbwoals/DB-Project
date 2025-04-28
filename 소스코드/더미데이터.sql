-- 데이터베이스 선택
USE dbproject;

-- 1. User 더미 데이터 (학생 1명, 교수 1명)

INSERT INTO User (아이디, 비밀번호, 이름, 역할, 학번, 교수번호, 학년, 학과, 전학기학점)
VALUES
('student01', 'hashedpassword1', '김학생', 'student', '20214045', NULL, 3, '컴퓨터소프트웨어공학과', 3.8),
('professor01', 'hashedpassword2', '이교수', 'professor', NULL, 'P12345', NULL, '컴퓨터소프트웨어공학과', NULL);

-- 2. Course 더미 데이터 (강의 2개)

INSERT INTO Course (강의번호, 강의명, 강의실, 교수UserID, 정원, 분반, 요일, 시작시간, 종료시간)
VALUES
('CSE101', '데이터베이스', 'A201', 2, 30, '1', '월', '09:00:00', '10:15:00'),
('CSE102', '운영체제', 'B301', 2, 40, '1', '수', '13:00:00', '14:15:00');

-- 3. Enroll 더미 데이터 (학생 1명이 강의 1개 신청)

INSERT INTO Enroll (UserID, 강의번호)
VALUES
(1, 'CSE101');

-- 4. ExtraEnroll 더미 데이터 (학생 1명이 빌넣 요청)

INSERT INTO ExtraEnroll (UserID, 강의번호, 사유, 상태)
VALUES
(1, 'CSE102', '수업을 꼭 듣고 싶습니다.', '대기');

-- 5. Cart 더미 데이터 (학생 1명이 강의 1개 장바구니 담기)

INSERT INTO Cart (UserID, 강의번호)
VALUES
(1, 'CSE102');UserID