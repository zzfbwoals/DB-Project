-- 데이터베이스 선택
USE dbproject;

INSERT INTO College (단과대학명) VALUES 
('공과대학'), 
('인문과학대학');

INSERT INTO Department (학과명, 단과대학ID) VALUES 
('컴퓨터소프트웨어공학과', 1), 
('영어영문학과', 2);

INSERT INTO User (사용자ID, 아이디, 비밀번호, 이름, 이메일, 이메일인증여부, 이메일인증코드, 학과ID, 학년, 전학기학점, 역할) VALUES
('20214045', 'student01', 'hashedpassword1', '김학생', 'student01@example.com', 1, NULL, 1, 3, 3.8, 'student'),
('P12345', 'professor01', 'hashedpassword2', '이교수', 'professor01@example.com', 1, NULL, 1, NULL, NULL, 'professor');

INSERT INTO Course (강의번호, 강의명, 강의실, 담당교수ID, 정원, 영어강의여부, 이수구분, 영역, 학과ID, 학점, 현재인원) VALUES
('10000', '데이터베이스', 'A201', 'P12345', 30, FALSE, '전공', '과학기술', 1, 3.0, 0),
('10001', '운영체제', 'B301', 'P12345', 40, FALSE, '전공', '과학기술', 1, 3.0, 0);

-- 데이터베이스 과목
INSERT INTO CourseTime (강의번호, 요일, 시작교시, 종료교시) VALUES
('10000', '월', '7A', '8A'),
('10000', '수', '2B', '3B');

-- 운영체제 과목
INSERT INTO CourseTime (강의번호, 요일, 시작교시, 종료교시) VALUES
('10001', '화', '4A', '5A'),
('10001', '목', '3B', '4B');

INSERT INTO Enroll (사용자ID, 강의번호) VALUES
('20214045', '10000');

INSERT INTO ExtraEnroll (사용자ID, 강의번호, 사유, 상태) VALUES
('20214045', '10001', '꼭 듣고 싶습니다.', '대기');

INSERT INTO Cart (사용자ID, 강의번호) VALUES
('20214045', '10001');
