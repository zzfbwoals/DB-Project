-- 데이터베이스 선택
USE dbproject;

CREATE VIEW StudentTimeTable AS
SELECT 
    U.학번,
    U.이름 AS 학생이름,
    D.학과명 AS 학생학과,
    C.강의번호,
    C.강의명,
    C.강의실,
    CT.요일,
    CT.시작교시,
    CT.종료교시,
    C.학점,
    P.이름 AS 교수이름,
    PD.학과명 AS 교수학과
FROM Enroll E
JOIN User U ON E.학번 = U.학번
JOIN Course C ON E.강의번호 = C.강의번호
JOIN CourseTime CT ON C.강의번호 = CT.강의번호
JOIN User P ON C.담당교수 = P.학번
LEFT JOIN Department D ON U.학과ID = D.학과ID
LEFT JOIN Department PD ON P.학과ID = PD.학과ID
WHERE U.역할 = 'student';


/* 
특정 학생의 시간표만 조회할 때
SELECT *
FROM StudentTimeTable
WHERE 사용자ID = '20214045'
ORDER BY 
  FIELD(요일, '월', '화', '수', '목', '금'),
  시작교시;
 */