USE dbproject;

-- 단과대학
CREATE TABLE College (
    단과대학ID INT AUTO_INCREMENT PRIMARY KEY,
    단과대학명 VARCHAR(50) NOT NULL
);

-- 학과
CREATE TABLE Department (
    학과ID INT AUTO_INCREMENT PRIMARY KEY,
    학과명 VARCHAR(50) NOT NULL,
    단과대학ID INT,
    FOREIGN KEY (단과대학ID) REFERENCES College(단과대학ID)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- 사용자
CREATE TABLE User (
    사용자ID VARCHAR(20) PRIMARY KEY,
    아이디 VARCHAR(50) UNIQUE NOT NULL,
    비밀번호 VARCHAR(255) NOT NULL,
    이름 VARCHAR(50) NOT NULL,
    이메일 VARCHAR(100) UNIQUE NOT NULL, -- ✅ 이메일 추가
    이메일인증여부 BOOLEAN DEFAULT 0,    -- ✅ 이메일 인증 여부 추가 (0: 미인증, 1: 인증)
    이메일인증코드 VARCHAR(255),          -- ✅ 이메일 인증 코드 추가
    학과ID INT,
    학년 INT,
    전학기학점 FLOAT,
    역할 VARCHAR(20) NOT NULL,
    FOREIGN KEY (학과ID) REFERENCES Department(학과ID)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- 강의
CREATE TABLE Course (
    강의번호 VARCHAR(5) PRIMARY KEY,
    강의명 VARCHAR(100) NOT NULL,
    강의실 VARCHAR(50) NOT NULL,
    담당교수ID VARCHAR(20),
    정원 INT NOT NULL,
    영어강의여부 BOOLEAN DEFAULT FALSE,
    이수구분 VARCHAR(20) NOT NULL,
    영역 VARCHAR(20) NOT NULL,
    학과ID INT,
    학점 FLOAT NOT NULL,
    현재인원 INT DEFAULT 0,
    FOREIGN KEY (담당교수ID) REFERENCES User(사용자ID)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    FOREIGN KEY (학과ID) REFERENCES Department(학과ID)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

-- 강의시간
CREATE TABLE CourseTime (
    시간ID INT AUTO_INCREMENT PRIMARY KEY,
    강의번호 VARCHAR(5),
    요일 ENUM('월', '화', '수', '목', '금') NOT NULL,
    시작교시 VARCHAR(5) NOT NULL,
    종료교시 VARCHAR(5) NOT NULL,
    FOREIGN KEY (강의번호) REFERENCES Course(강의번호)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- 수강신청
CREATE TABLE Enroll (
    신청번호 INT AUTO_INCREMENT PRIMARY KEY,
    사용자ID VARCHAR(20),
    강의번호 VARCHAR(5),
    FOREIGN KEY (사용자ID) REFERENCES User(사용자ID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (강의번호) REFERENCES Course(강의번호)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE (사용자ID, 강의번호)
);

-- 빌넣요청
CREATE TABLE ExtraEnroll (
    요청번호 INT AUTO_INCREMENT PRIMARY KEY,
    사용자ID VARCHAR(20),
    강의번호 VARCHAR(5),
    사유 TEXT,
    상태 VARCHAR(10) DEFAULT '대기',
    FOREIGN KEY (사용자ID) REFERENCES User(사용자ID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (강의번호) REFERENCES Course(강의번호)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE (사용자ID, 강의번호)
);

-- 장바구니
CREATE TABLE Cart (
    장바구니번호 INT AUTO_INCREMENT PRIMARY KEY,
    사용자ID VARCHAR(20),
    강의번호 VARCHAR(5),
    FOREIGN KEY (사용자ID) REFERENCES User(사용자ID)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    FOREIGN KEY (강의번호) REFERENCES Course(강의번호)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    UNIQUE (사용자ID, 강의번호)
);
