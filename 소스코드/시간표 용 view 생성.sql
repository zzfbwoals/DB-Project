-- 데이터베이스 선택
USE dbproject;

CREATE VIEW StudentTimeTable AS
SELECT 
    U.userID,         -- 학생 ID
    U.userName,       -- 학생 이름
    D.departmentName, -- 학생 학과
    C.courseID,       -- 강의 ID
    C.courseName,     -- 강의명
    C.classroom,      -- 강의실
    CT.dayOfWeek,     -- 요일
    CT.startPeriod,   -- 시작 시간
    CT.endPeriod,     -- 종료 시간
    C.credits,        -- 학점
    P.userName,       -- 교수 이름
    PD.departmentName -- 교수 학과
FROM Enroll E
JOIN User U ON E.userID = U.userID
JOIN Course C ON E.courseID = C.courseID
JOIN CourseTime CT ON C.courseID = CT.courseID
JOIN User P ON C.professorID = P.userID
LEFT JOIN Department D ON U.departmentID = D.departmentID
LEFT JOIN Department PD ON P.departmentID = PD.departmentID
WHERE U.userRole = 'student';


/* 
특정 학생의 시간표만 조회할 때
SELECT *
FROM StudentTimeTable
WHERE userID = '20214045'
ORDER BY 
  FIELD(dayOfWeek, '월', '화', '수', '목', '금'),
  startPeriod;
 */