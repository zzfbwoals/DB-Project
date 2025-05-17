-- 데이터베이스 선택
USE dbproject;

INSERT INTO College (단과대학명) VALUES 
('의과대학'), 
('자연과학대학'),
('인문사회과학대학'),
('글로벌경영대학'),
('공과대학'),
('SW융합대학'),
('의료과학대학'),
('SCH미디어랩스');

INSERT INTO Department (학과명, 단과대학ID) VALUES 
('의예과', 1), ('의학과', 1), ('간호학과', 1),
('화학과', 2), ('식품영양학과', 2), ('한경보건학과', 2), ('생명과학과', 2), ('스포츠과학과', 2), ('사회체육학과', 2), ('스포츠의학과', 2),
('유아교육과', 3), ('특수교육과', 3), ('청소년교육상담학과', 3), ('법학과', 3), ('행정학과', 3), ('경찰행정학과', 3), ('사회복지학과', 3),
('경영학과', 4), ('국제통상학과', 4), ('관광경영학과', 4), ('경제금융학과', 4), ('IT금융경영학과', 4), ('글로벌문화산업학과', 4), ('회계학과', 4),
('컴퓨터공학과', 5), ('정보통신공학과', 5), ('전자공학과', 5), ('전기공학과', 5), ('전자정보공학과', 5), ('나노화학공학과', 5), ('에너지환경공학과', 5), ('디스플레이신소재공학과', 5), ('기계공학과', 5),
('컴퓨터소프트웨어공학과', 6), ('정보보호학과', 6), ('의료IT공학과', 6), ('AI빅데이터학과', 6), ('사물인터넷학과', 6), ('메타버스&게임학과', 6),
('보건행정경영학과', 7), ('의료생명공학과', 7), ('임상병리학과', 7), ('작업치료학과', 7), ('의약공학과', 7), ('의공학과', 7),
('한국문화콘텐츠학과', 8), ('영미학과', 8), ('중국학과', 8), ('미디어커뮤니케이션학과', 8), ('건축학과', 8), ('디지털애니메이션학과', 8), ('스마트자동차학과', 8), ('공연영상학과', 8), ('에너지공학과', 8), ('탄소중립학과', 8), ('헬스케어융합전공', 8), ('바이오의약전공', 8);


-- 사용자
INSERT INTO User (학번, 비밀번호, 이름, 이메일, 이메일인증여부, 이메일인증코드, 관리자승인여부, 학과ID, 학년, 전학기학점, 역할) VALUES
('20214045', 'hashedpassword1', '홍길동', 'student01@example.com', 1, NULL, '승인', 33, 3, 3.8, 'student'),
('20024001', 'hashedpassword2', '김수현', 'professor01@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024002', 'hashedpassword2', '김대영', 'professor02@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024003', 'hashedpassword2', '김명숙', 'professor03@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024004', 'hashedpassword2', '박두순', 'professor04@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024005', 'hashedpassword2', '김석훈', 'professor05@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024006', 'hashedpassword2', '송인석', 'professor06@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024007', 'hashedpassword2', '마준', 'professor07@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024008', 'hashedpassword2', '소콤켕', 'professor08@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024009', 'hashedpassword2', '홍민', 'professor09@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024010', 'hashedpassword2', '가혜영', 'professor10@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024011', 'hashedpassword2', '김동욱', 'professor11@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024012', 'hashedpassword2', '서지윤', 'professor12@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024013', 'hashedpassword2', '김성엽', 'professor13@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024014', 'hashedpassword2', '이임영', 'professor14@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024015', 'hashedpassword2', '김철수', 'professor15@example.com', 1, NULL, '승인', 1, NULL, NULL, 'professor'),
('20024016', 'hashedpassword2', '신짱구', 'professor16@example.com', 1, NULL, '승인', 2, NULL, NULL, 'professor'),
('20024017', 'hashedpassword2', '이기동', 'professor17@example.com', 1, NULL, '승인', 3, NULL, NULL, 'professor'),
('20024018', 'hashedpassword2', '박수빈', 'professor18@example.com', 1, NULL, '승인', 4, NULL, NULL, 'professor'),
('20024019', 'hashedpassword2', '최정민', 'professor19@example.com', 1, NULL, '승인', 5, NULL, NULL, 'professor'),
('20024020', 'hashedpassword2', '윤하늘', 'professor20@example.com', 1, NULL, '승인', 6, NULL, NULL, 'professor'),
('20024021', 'hashedpassword2', '정도윤', 'professor21@example.com', 1, NULL, '승인', 7, NULL, NULL, 'professor'),
('20024022', 'hashedpassword2', '강지환', 'professor22@example.com', 1, NULL, '승인', 8, NULL, NULL, 'professor'),
('20024023', 'hashedpassword2', '임하린', 'professor23@example.com', 1, NULL, '승인', 9, NULL, NULL, 'professor'),
('20024024', 'hashedpassword2', '배지수', 'professor24@example.com', 1, NULL, '승인', 10, NULL, NULL, 'professor'),
('20024025', 'hashedpassword2', '오세연', 'professor25@example.com', 1, NULL, '승인', 11, NULL, NULL, 'professor'),
('20024026', 'hashedpassword2', '문다온', 'professor26@example.com', 1, NULL, '승인', 12, NULL, NULL, 'professor'),
('20024027', 'hashedpassword2', '서준혁', 'professor27@example.com', 1, NULL, '승인', 13, NULL, NULL, 'professor'),
('20024028', 'hashedpassword2', '조혜민', 'professor28@example.com', 1, NULL, '승인', 14, NULL, NULL, 'professor'),
('20024029', 'hashedpassword2', '하유리', 'professor29@example.com', 1, NULL, '승인', 15, NULL, NULL, 'professor'),
('20024030', 'hashedpassword2', '홍나은', 'professor30@example.com', 1, NULL, '승인', 16, NULL, NULL, 'professor'),
('20024031', 'hashedpassword2', '장예찬', 'professor31@example.com', 1, NULL, '승인', 17, NULL, NULL, 'professor'),
('20024032', 'hashedpassword2', '권동민', 'professor32@example.com', 1, NULL, '승인', 18, NULL, NULL, 'professor'),
('20024033', 'hashedpassword2', '양정우', 'professor33@example.com', 1, NULL, '승인', 19, NULL, NULL, 'professor'),
('20024034', 'hashedpassword2', '남유진', 'professor34@example.com', 1, NULL, '승인', 20, NULL, NULL, 'professor'),
('20024035', 'hashedpassword2', '정세린', 'professor35@example.com', 1, NULL, '승인', 21, NULL, NULL, 'professor'),
('20024036', 'hashedpassword2', '전성현', 'professor36@example.com', 1, NULL, '승인', 22, NULL, NULL, 'professor'),
('20024037', 'hashedpassword2', '고유진', 'professor37@example.com', 1, NULL, '승인', 23, NULL, NULL, 'professor'),
('20024038', 'hashedpassword2', '유하늘', 'professor38@example.com', 1, NULL, '승인', 24, NULL, NULL, 'professor'),
('20024039', 'hashedpassword2', '신예빈', 'professor39@example.com', 1, NULL, '승인', 25, NULL, NULL, 'professor'),
('20024040', 'hashedpassword2', '노시우', 'professor40@example.com', 1, NULL, '승인', 26, NULL, NULL, 'professor'),
('20024041', 'hashedpassword2', '임채원', 'professor41@example.com', 1, NULL, '승인', 27, NULL, NULL, 'professor'),
('20024042', 'hashedpassword2', '김동현', 'professor42@example.com', 1, NULL, '승인', 28, NULL, NULL, 'professor'),
('20024043', 'hashedpassword2', '윤태양', 'professor43@example.com', 1, NULL, '승인', 29, NULL, NULL, 'professor'),
('20024044', 'hashedpassword2', '백승호', 'professor44@example.com', 1, NULL, '승인', 30, NULL, NULL, 'professor'),
('20024045', 'hashedpassword2', '정지호', 'professor45@example.com', 1, NULL, '승인', 31, NULL, NULL, 'professor'),
('20024046', 'hashedpassword2', '차수빈', 'professor46@example.com', 1, NULL, '승인', 32, NULL, NULL, 'professor'),
('20024047', 'hashedpassword2', '오태현', 'professor47@example.com', 1, NULL, '승인', 33, NULL, NULL, 'professor'),
('20024048', 'hashedpassword2', '이정은', 'professor48@example.com', 1, NULL, '승인', 34, NULL, NULL, 'professor'),
('20024049', 'hashedpassword2', '김해성', 'professor49@example.com', 1, NULL, '승인', 35, NULL, NULL, 'professor'),
('20024050', 'hashedpassword2', '최서윤', 'professor50@example.com', 1, NULL, '승인', 36, NULL, NULL, 'professor'),
('20024051', 'hashedpassword2', '황지우', 'professor51@example.com', 1, NULL, '승인', 37, NULL, NULL, 'professor'),
('20024052', 'hashedpassword2', '배태현', 'professor52@example.com', 1, NULL, '승인', 38, NULL, NULL, 'professor'),
('20024053', 'hashedpassword2', '윤가람', 'professor53@example.com', 1, NULL, '승인', 39, NULL, NULL, 'professor'),
('20024054', 'hashedpassword2', '강시훈', 'professor54@example.com', 1, NULL, '승인', 40, NULL, NULL, 'professor'),
('20024055', 'hashedpassword2', '도하윤', 'professor55@example.com', 1, NULL, '승인', 41, NULL, NULL, 'professor'),
('20024056', 'hashedpassword2', '조연우', 'professor56@example.com', 1, NULL, '승인', 42, NULL, NULL, 'professor'),
('20024057', 'hashedpassword2', '서이준', 'professor57@example.com', 1, NULL, '승인', 43, NULL, NULL, 'professor'),
('20024058', 'hashedpassword2', '한지후', 'professor58@example.com', 1, NULL, '승인', 44, NULL, NULL, 'professor'),
('20024059', 'hashedpassword2', '권다연', 'professor59@example.com', 1, NULL, '승인', 45, NULL, NULL, 'professor'),
('20024060', 'hashedpassword2', '이재현', 'professor60@example.com', 1, NULL, '승인', 46, NULL, NULL, 'professor'),
('20024061', 'hashedpassword2', '장도윤', 'professor61@example.com', 1, NULL, '승인', 47, NULL, NULL, 'professor'),
('20024062', 'hashedpassword2', '백지민', 'professor62@example.com', 1, NULL, '승인', 48, NULL, NULL, 'professor'),
('20024063', 'hashedpassword2', '민도현', 'professor63@example.com', 1, NULL, '승인', 49, NULL, NULL, 'professor'),
('20024064', 'hashedpassword2', '노가은', 'professor64@example.com', 1, NULL, '승인', 50, NULL, NULL, 'professor'),
('20024065', 'hashedpassword2', '천서연', 'professor65@example.com', 1, NULL, '승인', 51, NULL, NULL, 'professor'),
('20024066', 'hashedpassword2', '홍세아', 'professor66@example.com', 1, NULL, '승인', 52, NULL, NULL, 'professor'),
('20024067', 'hashedpassword2', '오나윤', 'professor67@example.com', 1, NULL, '승인', 53, NULL, NULL, 'professor'),
('20024068', 'hashedpassword2', '임하은', 'professor68@example.com', 1, NULL, '승인', 54, NULL, NULL, 'professor'),
('20024069', 'hashedpassword2', '윤나래', 'professor69@example.com', 1, NULL, '승인', 55, NULL, NULL, 'professor');
-- 강의

-- 강의 시간

-- 수강 신청

-- 빌넣 요청

-- 장바구니
