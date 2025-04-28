-- 1. 새로운 유저 생성
CREATE USER 'dbproject_user'@'localhost' IDENTIFIED BY 'Gkrrytlfj@@33';

-- 2. 새로운 데이터베이스 생성
CREATE DATABASE dbproject;

-- 3. 유저에게 권한 부여
GRANT ALL PRIVILEGES ON dbproject.* TO 'dbproject_user'@'localhost';

-- 4. 권한 적용
FLUSH PRIVILEGES;