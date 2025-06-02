-- 데이터베이스 생성
CREATE DATABASE dbproject;

USE dbproject;

-- 단과대학 테이블
CREATE TABLE College (
    collegeID INT AUTO_INCREMENT PRIMARY KEY, -- 단과대학 ID
    collegeName VARCHAR(50) NOT NULL          -- 단과대학명
);

-- 학과 테이블
CREATE TABLE Department (
    departmentID INT AUTO_INCREMENT PRIMARY KEY, -- 학과 ID
    departmentName VARCHAR(50) NOT NULL,         -- 학과명
    collegeID INT,                               -- 단과대학 ID
    FOREIGN KEY (collegeID) REFERENCES College(collegeID)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- 사용자 테이블 
CREATE TABLE User (
    userID VARCHAR(20) PRIMARY KEY,         -- 학번(아이디)
    userPassword VARCHAR(255) NOT NULL,     -- 비밀번호
    userName VARCHAR(50) NOT NULL,          -- 이름
    adminApproval ENUM('대기', '승인', '거절') DEFAULT '대기', -- 관리자 승인 상태
    departmentID INT,                       -- 학과 ID
    grade INT,                              -- 학년
    lastSemesterCredits FLOAT,              -- 지난 학기 성적
    userRole ENUM('student','professor','admin') NOT NULL,          -- 역할
    FOREIGN KEY (departmentID) REFERENCES Department(departmentID)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- 강의 테이블
CREATE TABLE Course (
    courseID VARCHAR(5) PRIMARY KEY, -- 강의번호
    courseName VARCHAR(100) NOT NULL,-- 강의명
    classroom VARCHAR(50),           -- 강의실
    professorID VARCHAR(20),         -- 담당교수
    capacity INT,                    -- 정원
    creditType VARCHAR(20), 		 -- 이수구분
    area VARCHAR(20),       		 -- 영역
    grade VARCHAR(10),               -- 학년
    departmentID INT,                -- 학과
    credits INT,                     -- 학점
    currentEnrollment INT DEFAULT 0, -- 현재 수강신청 인원
    FOREIGN KEY (professorID) REFERENCES User(userID)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    FOREIGN KEY (departmentID) REFERENCES Department(departmentID)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- 강의 시간표 테이블
CREATE TABLE CourseTime (
    courseTimeID INT AUTO_INCREMENT PRIMARY KEY, -- 강의시간표 ID
    courseID VARCHAR(5),                         -- 강의번호
    dayOfWeek VARCHAR(5),                        -- 요일
    startPeriod VARCHAR(5),                      -- 시작시간
    endPeriod VARCHAR(5),                        -- 종료시간
    FOREIGN KEY (courseID) REFERENCES Course(courseID)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- 수강신청 테이블
CREATE TABLE Enroll (
    enrollID INT AUTO_INCREMENT PRIMARY KEY, -- 수강신청 ID
    userID VARCHAR(20),                      -- 학번
    courseID VARCHAR(5),                     -- 강의번호
    FOREIGN KEY (userID) REFERENCES User(userID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (courseID) REFERENCES Course(courseID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE (userID, courseID)
);

-- 빌넣 요청 테이블
CREATE TABLE ExtraEnroll (
    extraEnrollID INT AUTO_INCREMENT PRIMARY KEY, -- 빌넣 요청 ID
    userID VARCHAR(20),                           -- 학번
    courseID VARCHAR(5),                          -- 강의번호
    reason VARCHAR(100),                          -- 사유
    extraEnrollStatus VARCHAR(10) DEFAULT '대기', -- 빌넣 요청 상태
    FOREIGN KEY (userID) REFERENCES User(userID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (courseID) REFERENCES Course(courseID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE (userID, courseID)
);

-- 장바구니 테이블
CREATE TABLE Cart (
    cartID INT AUTO_INCREMENT PRIMARY KEY,       -- 장바구니 ID
    userID VARCHAR(20),                          -- 학번
    courseID VARCHAR(5),                         -- 강의번호
    FOREIGN KEY (userID) REFERENCES User(userID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (courseID) REFERENCES Course(courseID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE (userID, courseID)
);
