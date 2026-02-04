# 수강신청 시스템 + 빌넣 시스템

## 개발 환경

- XAMPP (Apache)
- PHP
- MySQL
- Google Chrome
- HTML + CSS + JavaScript

## 폴더 구조

```bash
├── 데이터베이스 테이블 생성.sql
├── 권한 관리.sql
├── 더미데이터.sql
├── 트리거 및 프로시저.sql
├── login.php
├── signup.php
├── myPage.php
├── enroll.php
├── extraEnroll.php
├── professor.php
├── admin.php
└── cart.php
````

## ⚙ 설치 및 실행 방법

### 1. XAMPP 설치 및 설정

1. [XAMPP 공식 사이트](https://www.apachefriends.org/)에서 다운로드 및 설치
2. `htdocs/` 폴더에 해당 프로젝트 전체 복사
3. XAMPP Control Panel 실행 → Apache **Start**

### 2. MySQL + MySQL Workbench 설치

1. [MySQL 공식 사이트](https://dev.mysql.com/downloads/installer/)에서 **MySQL Installer (Windows)** 다운로드
2. 설치 항목 중 다음 2가지를 반드시 선택:
   * MySQL Server (8.0 이상)
   * MySQL Workbench
3. MySQL Server 설치 중 **루트 계정(root)** 비밀번호 설정 → 꼭 기억해두기!
4. 설치 완료 후 MySQL Workbench 실행 → root 계정으로 로그인

### 3. 데이터베이스 구축

1. Workbench → `Local instance MySQL` 연결
2. `데이터베이스 테이블 생성.sql` 실행
3. `더미데이터.sql` 실행
4. `트리거 및 프로시저.sql` 실행
5. `권한 관리.sql` 실행

※ 순서대로 실행하지 않으면 외래키 제약 조건으로 인해 오류 발생 가능

### 4. 사용자 계정 정보

| 역할  | ID       | 비밀번호 |
| --- | -------- | ---- |
| 관리자 | 관리자      | rhksflwk |
| 교수  | 20024001 | rhksflwk |
| 학생  | 20214045 | rhksflwk |

### 5. 실행 경로

* 로그인: [http://localhost/login.php](http://localhost/login.php)
* 회원가입: [http://localhost/signup.php](http://localhost/signup.php)
* 마이페이지: [http://localhost/signup.php](http://localhost/myPage.php) (세션 관리로 인해 URL로 이동 불가)
* 학생 메인: [http://localhost/enroll.php](http://localhost/enroll.php) (세션 관리로 인해 URL로 이동 불가)
* 교수 페이지: [http://localhost/professor.php](http://localhost/professor.php) (세션 관리로 인해 URL로 이동 불가)
* 관리자 페이지: [http://localhost/admin.php](http://localhost/admin.php) (세션 관리로 인해 URL로 이동 불가)


## 테스트 방법

* 로그인 기능: login.php 접속 후 `3.사용자 계정 정보`에 나온 계정으로 로그인
* 회원가입 기능: signup.php 접속 후 회원가입 진행 → `3.사용자 계정 정보`에 관리자 아이디로 로그인 후 회원가입 승인/거절
* 마이페이지 기능: 학생 페이지, 교수 페이지 접속 후 로그아웃 왼쪽 마이페이지 버튼 클릭 → myPage.php로 이동 → 새 비밀번호 변경 혹은 회원탈퇴
* 장바구니 기능: `3.사용자 계정 정보`에 학생 아이디로 로그인 → `enroll.php`로 이동 → 좌측 메뉴의 예비수강신청 클릭 → 예비수강신청 진행
* 수강 신청: `3.사용자 계정 정보`에 학생 아이디로 로그인 → `enroll.php`로 이동 → 수강신청 진행
* 빌넣 기능: `MySQL Workbench` 접속 → 쿼리 실행
 
  ``` sql
  UPDATE dbproject.course SET currentEnrollment = 30 WHERE '12384'; -- DB프로그래밍 강의 현재원 30으로 변경
  ```

  → `3.사용자 계정 정보`에 학생 아이디로 로그인 → `enroll.php`로 이동 → 좌측 메뉴의 빌넣요청 클릭 → 검색어에 `DB프로그래밍` 검색 후 빌넣요청
* 빌넣 승인 기능: signup.php 접속 후 회원가입 진행 → `3.사용자 계정 정보`에 교수 아이디로 로그인 후 빌넣요청 승인/거절


## 대표 인터페이스

### 1. 로그인 페이지
<img width="502" height="667" alt="image" src="https://github.com/user-attachments/assets/50ede559-7b23-4433-b5bd-b38f413325b1" />

### 2. 메인 수강신청 페이지
<img width="1094" height="515" alt="545030051-e4ee65d6-0eda-4a7d-a643-fafc00fc99b4" src="https://github.com/user-attachments/assets/98876432-c6cb-47f2-9b6c-b60654456cc7" />



---
