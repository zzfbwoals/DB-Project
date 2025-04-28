-- 데이터베이스 선택
USE dbproject;

CREATE VIEW StudentTimeTable AS
SELECT 
    U.사용자ID,
    U.아이디,
    U.이름 AS 학생이름,
    D.학과명 AS 학생학과,
    C.강의번호,
    C.강의명,
    C.강의실,
    C.요일및시간,
    C.학점,
    P.이름 AS 교수이름,
    PD.학과명 AS 교수학과
FROM Enroll E
JOIN User U ON E.사용자ID = U.사용자ID
JOIN Course C ON E.강의번호 = C.강의번호
JOIN User P ON C.담당교수ID = P.사용자ID
LEFT JOIN Department D ON U.학과ID = D.학과ID
LEFT JOIN Department PD ON P.학과ID = PD.학과ID
WHERE U.역할 = 'student';
