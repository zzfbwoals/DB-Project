-- 데이터베이스 선택
USE dbproject;

CREATE VIEW StudentTimeTable AS
SELECT 
    U.UserID,
    U.아이디,
    U.이름 AS 학생이름,
    C.강의명,
    C.강의실,
    C.요일,
    C.시작시간,
    C.종료시간,
    P.이름 AS 교수이름
FROM Enroll E
JOIN User U ON E.UserID = U.UserID
JOIN Course C ON E.강의번호 = C.강의번호
JOIN User P ON C.교수UserID = P.UserID
WHERE U.역할 = 'student';
