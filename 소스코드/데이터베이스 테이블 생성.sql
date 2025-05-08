USE dbproject;

-- 단과대학 테이블
CREATE TABLE College (
    단과대학ID INT AUTO_INCREMENT PRIMARY KEY,
    단과대학명 VARCHAR(50) NOT NULL
);

-- 학과 테이블
CREATE TABLE Department (
    학과ID INT AUTO_INCREMENT PRIMARY KEY,
    학과명 VARCHAR(50) NOT NULL,
    단과대학ID INT,
    FOREIGN KEY (단과대학ID) REFERENCES College(단과대학ID)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- 사용자(User) 테이블 (학번 통일)
CREATE TABLE User (
    학번 VARCHAR(20) PRIMARY KEY,
    비밀번호 VARCHAR(255) NOT NULL,
    이름 VARCHAR(50) NOT NULL,
    이메일 VARCHAR(100) UNIQUE NOT NULL,
    이메일인증여부 BOOLEAN DEFAULT 0,
    이메일인증코드 VARCHAR(255),
    관리자승인여부 ENUM('대기', '승인', '거절') DEFAULT '대기',
    학과ID INT,
    학년 INT,
    전학기학점 FLOAT,
    역할 VARCHAR(20) NOT NULL,
    FOREIGN KEY (학과ID) REFERENCES Department(학과ID)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- 강의(Course) 테이블
CREATE TABLE Course (
    강의번호 VARCHAR(5) PRIMARY KEY,
    강의명 VARCHAR(100) NOT NULL,
    강의실 VARCHAR(50) NOT NULL,
    담당교수 VARCHAR(20),
    정원 INT NOT NULL,
    이수구분 VARCHAR(20) NOT NULL,
    영역 VARCHAR(20) NOT NULL,
    학년 VARCHAR(10) NOT NULL,
    학과ID INT,
    학점 FLOAT NOT NULL,
    현재인원 INT DEFAULT 0,
    FOREIGN KEY (담당교수) REFERENCES User(학번)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    FOREIGN KEY (학과ID) REFERENCES Department(학과ID)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- 강의 시간표(CourseTime) 테이블
CREATE TABLE CourseTime (
    시간ID INT AUTO_INCREMENT PRIMARY KEY,
    강의번호 VARCHAR(5),
    시간 INT,
    요일 VARCHAR(5) NOT NULL,
    시작교시 VARCHAR(5) NOT NULL,
    종료교시 VARCHAR(5) NOT NULL,
    FOREIGN KEY (강의번호) REFERENCES Course(강의번호)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- 수강신청(Enroll) 테이블
CREATE TABLE Enroll (
    신청번호 INT AUTO_INCREMENT PRIMARY KEY,
    학번 VARCHAR(20),
    강의번호 VARCHAR(5),
    FOREIGN KEY (학번) REFERENCES User(학번)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (강의번호) REFERENCES Course(강의번호)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE (학번, 강의번호)
);

-- 빌넣 요청(ExtraEnroll) 테이블
CREATE TABLE ExtraEnroll (
    요청번호 INT AUTO_INCREMENT PRIMARY KEY,
    학번 VARCHAR(20),
    강의번호 VARCHAR(5),
    사유 TEXT,
    상태 VARCHAR(10) DEFAULT '대기',
    FOREIGN KEY (학번) REFERENCES User(학번)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (강의번호) REFERENCES Course(강의번호)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE (학번, 강의번호)
);

-- 장바구니(Cart) 테이블
CREATE TABLE Cart (
    장바구니번호 INT AUTO_INCREMENT PRIMARY KEY,
    학번 VARCHAR(20),
    강의번호 VARCHAR(5),
    FOREIGN KEY (학번) REFERENCES User(학번)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (강의번호) REFERENCES Course(강의번호)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE (학번, 강의번호)
);
