CREATE USER 'admin_user'@'localhost' IDENTIFIED BY 'AdminPass123!';     -- admin.php 용 계정
CREATE USER 'professor_user'@'localhost' IDENTIFIED BY 'ProfPass123!';  -- professor.php 용 계정
CREATE USER 'student_user'@'localhost' IDENTIFIED BY 'StudentPass123!'; -- enroll.php, cart.php, extraenroll.php 용 계정
CREATE USER 'auth_user'@'localhost' IDENTIFIED BY 'AuthPass123!';       -- login.php, signup.php 용 계정

-- admin_user: 대부분의 테이블에 대해 모든 권한 부여, 모든 프로시저 및 함수 실행 가능
GRANT SELECT, INSERT, UPDATE, DELETE ON dbproject.* TO 'admin_user'@'localhost';
GRANT EXECUTE ON dbproject.* TO 'admin_user'@'localhost'; -- 모든 프로시저 및 함수 실행 권한

-- professor_user: ExtraEnroll, Enroll, Course 관련 권한 및 필요한 프로시저 실행 권한
GRANT SELECT, UPDATE ON dbproject.ExtraEnroll TO 'professor_user'@'localhost';          -- 빌넣요청 승인/거절
GRANT SELECT, INSERT, DELETE ON dbproject.Enroll TO 'professor_user'@'localhost';       -- 수강신청 추가/삭제
GRANT SELECT, INSERT, UPDATE, DELETE ON dbproject.Course TO 'professor_user'@'localhost'; -- 강의 관리
GRANT SELECT, INSERT, UPDATE, DELETE ON dbproject.CourseTime TO 'professor_user'@'localhost'; -- 강의 시간표 관리
GRANT SELECT ON dbproject.Department TO 'professor_user'@'localhost';                   -- 학과 조회
GRANT SELECT ON dbproject.User TO 'professor_user'@'localhost';                         -- 학생 정보 조회
GRANT EXECUTE ON PROCEDURE dbproject.sp_approve_extra_enroll TO 'professor_user'@'localhost'; -- 빌넣 승인
GRANT EXECUTE ON PROCEDURE dbproject.sp_enroll_with_conflict_check TO 'professor_user'@'localhost'; -- 수강신청 관리
GRANT EXECUTE ON FUNCTION dbproject.period_to_numeric TO 'professor_user'@'localhost';   -- 충돌 검사용 함수

-- student_user: 본인 관련 데이터 조작 및 관련 프로시저 실행 권한
GRANT SELECT, INSERT, DELETE ON dbproject.Enroll TO 'student_user'@'localhost';         -- 수강신청/취소
GRANT SELECT, INSERT, DELETE ON dbproject.ExtraEnroll TO 'student_user'@'localhost';    -- 빌넣요청
GRANT SELECT, INSERT, UPDATE, DELETE ON dbproject.Cart TO 'student_user'@'localhost';   -- 장바구니
GRANT SELECT, UPDATE ON dbproject.Course TO 'student_user'@'localhost';                 -- 강의 조회
GRANT SELECT ON dbproject.User TO 'student_user'@'localhost';                           -- 본인 정보 조회
GRANT SELECT ON dbproject.CourseTime TO 'student_user'@'localhost';                     -- 시간표 조회
GRANT SELECT ON dbproject.Department TO 'student_user'@'localhost';                     -- 조인
GRANT SELECT ON dbproject.College TO 'student_user'@'localhost';                        -- 조인
GRANT EXECUTE ON PROCEDURE dbproject.sp_enroll_with_conflict_check TO 'student_user'@'localhost'; -- 수강신청
GRANT EXECUTE ON PROCEDURE dbproject.sp_cart_with_conflict_check TO 'student_user'@'localhost'; -- 장바구니 추가
GRANT EXECUTE ON PROCEDURE dbproject.sp_extra_enroll_with_conflict_check TO 'student_user'@'localhost'; -- 빌넣 요청
GRANT EXECUTE ON FUNCTION dbproject.period_to_numeric TO 'student_user'@'localhost';     -- 충돌 검사용 함수

-- auth_user: 로그인, 회원가입 관련 권한 부여
GRANT SELECT, INSERT, UPDATE, DELETE ON dbproject.User TO 'auth_user'@'localhost';      -- 로그인, 회원가입, 정보수정, 탈퇴
GRANT SELECT ON dbproject.Department TO 'auth_user'@'localhost';                        -- 회원가입 폼
GRANT SELECT ON dbproject.College TO 'auth_user'@'localhost';                           -- 회원가입 폼

-- 권한 적용
FLUSH PRIVILEGES;