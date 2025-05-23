CREATE USER 'admin_user'@'localhost' IDENTIFIED BY 'AdminPass123!';
CREATE USER 'professor_user'@'localhost' IDENTIFIED BY 'ProfPass123!';
CREATE USER 'student_user'@'localhost' IDENTIFIED BY 'StudentPass123!';

-- admin_user: 대부분의 테이블에 대해 모든 권한 부여 (User 승인/거절 등)
GRANT SELECT, INSERT, UPDATE, DELETE ON dbproject.* TO 'admin_user'@'localhost';

-- professor_user: ExtraEnroll, Enroll, Course 관련 권한 부여
GRANT SELECT, UPDATE ON dbproject.ExtraEnroll TO 'professor_user'@'localhost'; -- 빌넣요청 승인/거절
GRANT SELECT, INSERT ON dbproject.Enroll TO 'professor_user'@'localhost'; -- 수강신청 추가
GRANT SELECT, UPDATE ON dbproject.Course TO 'professor_user'@'localhost'; -- currentEnrollment 수정
GRANT SELECT ON dbproject.User TO 'professor_user'@'localhost'; -- 학생 정보 조회

-- student_user: 본인 관련 데이터만 조작 가능
GRANT SELECT, INSERT, DELETE ON dbproject.Enroll TO 'student_user'@'localhost'; -- 수강신청/취소
GRANT SELECT, INSERT, DELETE ON dbproject.ExtraEnroll TO 'student_user'@'localhost'; -- 빌넣요청
GRANT SELECT, UPDATE ON dbproject.Cart TO 'student_user'@'localhost'; -- 장바구니
GRANT SELECT, UPDATE ON dbproject.Course TO 'student_user'@'localhost'; -- 강의 조회, 수강취소
GRANT SELECT ON dbproject.User TO 'student_user'@'localhost'; -- 본인 정보 조회
GRANT SELECT ON dbproject.CourseTime TO 'student_user'@'localhost'; -- 시간표 조회
GRANT SELECT ON dbproject.Department TO 'student_user'@'localhost'; -- 조인
GRANT SELECT ON dbproject.College TO 'student_user'@'localhost'; -- 조인

-- 권한 적용
FLUSH PRIVILEGES;