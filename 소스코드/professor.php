<?php
// ---------------------------------------
// 초기 설정
// ---------------------------------------

session_start();

// 사용자가 로그인되어 있고 교수인지 확인
if (!isset($_SESSION["userID"]) || $_SESSION["userRole"] !== 'professor') {
    header("Location: login.php");
    exit();
}

// professor_user로 접속
$conn = new mysqli("localhost", "professor_user", "ProfPass123!", "dbproject");
if ($conn->connect_error) {
    die("DB 연결 실패: " . $conn->connect_error);
}
$conn->set_charset("utf8"); // UTF-8 인코딩 설정

// ---------------------------------------
// 데이터 조회
// ---------------------------------------

// 로그인한 교수 정보 조회
$professorID = $_SESSION["userID"];
$professorQuery = "SELECT userName FROM User WHERE userID = ?";
$stmt = $conn->prepare($professorQuery);
$stmt->bind_param("s", $professorID);
$stmt->execute();
$professorResult = $stmt->get_result();
$professorInfo = $professorResult->fetch_assoc();
$stmt->close();

// 학과 목록 조회
$deptQuery = "SELECT departmentID, departmentName FROM Department";
$deptResult = $conn->query($deptQuery);
if (!$deptResult) {
    error_log("학과 조회 실패: " . $conn->error);
}

// *** 추가: Course 테이블에서 area 목록 가져오기 ***
$areaQuery = "SELECT DISTINCT area FROM Course WHERE area IS NOT NULL AND area != '' ORDER BY area";
$areaResult = $conn->query($areaQuery);
$areas = [];
if ($areaResult) {
    while ($row = $areaResult->fetch_assoc()) {
        $areas[] = $row['area'];
    }
    $areaResult->free();
}
// (중핵) 하단으로 정렬
$areas_normal = [];
$areas_core = [];
foreach ($areas as $area_item) {
    if (strpos($area_item, '(중핵)') !== false) {
        $areas_core[] = $area_item;
    } else {
        $areas_normal[] = $area_item;
    }
}
sort($areas_normal); // 일반 항목 가나다순 정렬
sort($areas_core);   // (중핵) 항목 가나다순 정렬
$areas = array_merge($areas_normal, $areas_core); // 두 배열을 합침

// 검색 키워드 처리
$searchKeyword = '';
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchKeyword = trim($_GET['search']);
}

// 해당 교수의 과목에 대한 빌넣요청 조회
$sql = "SELECT e.extraEnrollID, e.userID, e.courseID, e.reason, e.extraEnrollStatus, u.userName, c.courseName 
        FROM ExtraEnroll e
        JOIN User u ON e.userID = u.userID
        JOIN Course c ON e.courseID = c.courseID
        WHERE e.extraEnrollStatus = '대기' AND c.professorID = ?";
if ($searchKeyword) {
    $sql .= " AND e.reason LIKE ?";
}
$stmt = $conn->prepare($sql);
if ($searchKeyword) {
    $likeKeyword = '%' . $searchKeyword . '%';
    $stmt->bind_param("ss", $professorID, $likeKeyword);
} else {
    $stmt->bind_param("s", $professorID);
}
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// 해당 교수의 강의 목록 조회
$courseQuery = "SELECT courseID, courseName, currentEnrollment, capacity 
                FROM Course 
                WHERE professorID = ?";
$stmt = $conn->prepare($courseQuery);
$stmt->bind_param("s", $professorID);
$stmt->execute();
$courseResult = $stmt->get_result();
$stmt->close();

// ---------------------------------------
// 승인/거절 및 강의 수정/삭제/추가 처리
// ---------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // 개별 승인 처리
    if (isset($_POST["approve"])) {
        $extraEnrollID = $_POST["extraEnrollID"];
        error_log("개별 승인 시도: extraEnrollID=$extraEnrollID, professorID=$professorID");

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("CALL sp_approve_extra_enroll(?)");
            $stmt->bind_param("i", $extraEnrollID);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            echo "<script>alert('빌넣 요청이 승인되었습니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $errorMsg = $e->getCode() == 45000 ? $e->getMessage() : "승인 중 오류: " . $e->getMessage();
            error_log("승인 실패: $errorMsg, extraEnrollID=$extraEnrollID");
            echo "<script>alert('" . htmlspecialchars($errorMsg) . "'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }
    }
    // 개별 거절 처리
    elseif (isset($_POST["reject"])) {
        $userID = $_POST["userID"];
        $courseID = $_POST["courseID"];
        error_log("개별 거절 시도: userID=$userID, courseID=$courseID");

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE ExtraEnroll SET extraEnrollStatus = '거절' WHERE userID = ? AND courseID = ?");
            $stmt->bind_param("ss", $userID, $courseID);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            echo "<script>alert('빌넣 요청이 거절되었습니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("거절 실패: " . $e->getMessage());
            echo "<script>alert('거절 중 오류: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }
    }
    // 일괄 승인/거절 처리
    elseif (isset($_POST["bulk_approve"]) || isset($_POST["bulk_reject"])) {
        if (isset($_POST["selected_items"]) && !empty($_POST["selected_items"])) {
            $selectedItems = $_POST["selected_items"];
            $status = isset($_POST["bulk_approve"]) ? '승인' : '거절';
            error_log("일괄 처리 시도: status=$status, items=" . implode(',', $selectedItems));

            $conn->begin_transaction();
            try {
                if ($status === '승인') {
                    foreach ($selectedItems as $item) {
                        list($userID, $courseID) = explode("_", $item);
                        // extraEnrollID 조회
                        $stmt = $conn->prepare("SELECT extraEnrollID FROM ExtraEnroll WHERE userID = ? AND courseID = ? AND extraEnrollStatus = '대기'");
                        $stmt->bind_param("ss", $userID, $courseID);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $extraEnrollID = $row['extraEnrollID'];
                            $stmt->close();
                            // sp_approve_extra_enroll 호출
                            $stmt = $conn->prepare("CALL sp_approve_extra_enroll(?)");
                            $stmt->bind_param("i", $extraEnrollID);
                            $stmt->execute();
                            $stmt->close();
                        } else {
                            $stmt->close();
                            throw new Exception("유효하지 않은 빌넣 요청: userID=$userID, courseID=$courseID");
                        }
                    }
                } else {
                    $placeholders = implode(",", array_fill(0, count($selectedItems), "(?, ?)"));
                    $stmt = $conn->prepare("UPDATE ExtraEnroll SET extraEnrollStatus = '거절' WHERE (userID, courseID) IN ($placeholders)");
                    $types = str_repeat("ss", count($selectedItems));
                    $params = [];
                    foreach ($selectedItems as $item) {
                        list($userID, $courseID) = explode("_", $item);
                        $params[] = $userID;
                        $params[] = $courseID;
                    }
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $stmt->close();
                }
                $conn->commit();
                echo "<script>alert('일괄 처리가 완료되었습니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
                exit();
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $errorMsg = $e->getCode() == 45000 ? $e->getMessage() : "일괄 처리 중 오류: " . $e->getMessage();
                error_log("일괄 처리 실패: $errorMsg");
                echo "<script>alert('" . htmlspecialchars($errorMsg) . "'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                error_log("일괄 처리 실패: " . $e->getMessage());
                echo "<script>alert('일괄 처리 중 오류: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
                exit();
            }
        } else {
            echo "<script>alert('선택된 항목이 없습니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }
    }
    // 강의 수정 처리
    elseif (isset($_POST["edit_course"])) {
        $courseID = trim($_POST["courseID"]);
        $courseName = trim($_POST["courseName"]);
        $capacity = (int)$_POST["capacity"];
        error_log("강의 수정 시도: courseID=$courseID, courseName=$courseName, capacity=$capacity");

        if (empty($courseID) || empty($courseName) || $capacity <= 0) {
            echo "<script>alert('모든 필드를 올바르게 입력해주세요.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE Course SET courseName = ?, capacity = ? WHERE courseID = ? AND professorID = ?");
            $stmt->bind_param("siss", $courseName, $capacity, $courseID, $professorID);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            echo "<script>alert('강의가 수정되었습니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("강의 수정 실패: " . $e->getMessage());
            echo "<script>alert('강의 수정 중 오류: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }
    }
    // 강의 삭제 처리
    elseif (isset($_POST["delete_course"])) {
        $courseID = trim($_POST["courseID"]);
        error_log("강의 삭제 시도: courseID=$courseID");

        if (empty($courseID)) {
            echo "<script>alert('유효하지 않은 강의번호입니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("DELETE FROM Course WHERE courseID = ? AND professorID = ?");
            $stmt->bind_param("ss", $courseID, $professorID);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            echo "<script>alert('강의가 삭제되었습니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("강의 삭제 실패: " . $e->getMessage());
            echo "<script>alert('강의 삭제 중 오류: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }
    }
    // 강의 추가 처리
    elseif (isset($_POST["add_course"])) {
        $courseID = trim($_POST["courseID"]);
        $courseName = trim($_POST["courseName"]);
        $classroom = trim($_POST["classroom"]);
        $creditType = $_POST["creditType"];
        $area = $_POST["area"] === "NULL" ? NULL : $_POST["area"];
        $grade = $_POST["grade"];
        $departmentID = $_POST["departmentID"];
        $credits = (int)$_POST["credits"];
        $capacity = (int)$_POST["capacity"];
        $times = isset($_POST["times"]) ? $_POST["times"] : [];
        error_log("강의 추가 시도: courseID=$courseID, courseName=$courseName");

        // 입력 유효성 검사
        $validInput = !empty($courseID) && preg_match('/^[A-Za-z0-9]{5}$/', $courseID) &&
                      !empty($courseName) && !empty($creditType) && 
                      !empty($grade) && !empty($departmentID) && 
                      $credits >= 1 && $credits <= 4 && $capacity >= 1;
        $validTimes = false;
        if (!empty($times)) {
            foreach ($times as $time) {
                if (!empty($time["dayOfWeek"]) && !empty($time["startPeriod"]) && !empty($time["endPeriod"])) {
                    $validTimes = true;
                    break;
                }
            }
        }

        if (!$validInput || !$validTimes) {
            echo "<script>alert('모든 필수 필드를 올바르게 입력해주세요.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }

        // courseID 중복 체크
        $checkCourse = $conn->prepare("SELECT COUNT(*) FROM Course WHERE courseID = ?");
        $checkCourse->bind_param("s", $courseID);
        $checkCourse->execute();
        $checkCourse->bind_result($count);
        $checkCourse->fetch();
        $checkCourse->close();

        if ($count > 0) {
            echo "<script>alert('이미 존재하는 강의번호입니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }

        // 시간 유효성 검사 (endPeriod >= startPeriod)
        $areTimePeriodsValid = true;
        foreach ($times as $time) {
            $start = $time["startPeriod"];
            $end = $time["endPeriod"];
            if (!empty($start) && !empty($end)) {
                if (is_numeric($start) && is_numeric($end) && (int)$end < (int)$start) {
                    $areTimePeriodsValid = false;
                    break;
                }
                if (preg_match('/[A-B]/', $start) || preg_match('/[A-B]/', $end)) {
                    $startNum = (int)preg_replace('/[A-B]/', '', $start);
                    $endNum = (int)preg_replace('/[A-B]/', '', $end);
                    $startLetter = preg_replace('/[0-9]/', '', $start);
                    $endLetter = preg_replace('/[0-9]/', '', $end);
                    if ($startNum > $endNum || ($startNum == $endNum && $startLetter == 'B' && $endLetter == 'A')) {
                        $areTimePeriodsValid = false;
                        break;
                    }
                }
            }
        }

        if (!$areTimePeriodsValid) {
            echo "<script>alert('종료 교시가 시작 교시보다 빠를 수 없습니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }

        $conn->begin_transaction();
        try {
            // Course 테이블에 삽입
            $stmt = $conn->prepare("INSERT INTO Course (courseID, courseName, classroom, professorID, capacity, creditType, area, grade, departmentID, credits, currentEnrollment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("ssssissssi", $courseID, $courseName, $classroom, $professorID, $capacity, $creditType, $area, $grade, $departmentID, $credits);
            $stmt->execute();
            $stmt->close();

            // CourseTime 테이블에 시간표 삽입
            foreach ($times as $time) {
                $dayOfWeek = $time["dayOfWeek"];
                $startPeriod = $time["startPeriod"];
                $endPeriod = $time["endPeriod"];
                if (!empty($dayOfWeek) && !empty($startPeriod) && !empty($endPeriod)) {
                    $stmt = $conn->prepare("INSERT INTO CourseTime (courseID, dayOfWeek, startPeriod, endPeriod) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $courseID, $dayOfWeek, $startPeriod, $endPeriod);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();
            echo "<script>alert('강의가 성공적으로 추가되었습니다.'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("강의 추가 실패: " . $e->getMessage());
            echo "<script>alert('강의 추가 중 오류: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='professor.php" . ($searchKeyword ? "?search=" . urlencode($searchKeyword) : "") . "';</script>";
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>순천향대학교 수강신청 시스템 - 교수</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Malgun Gothic', sans-serif;
        }

        body {
            background-color: #f5f5f5;
        }

        .section {
            width: 80%;
            max-width: 1000px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-top: 50px;
            margin-bottom: 50px;
        }

        .logo {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo img {
            width: 200px;
        }

        h2 {
            font-size: 22px;
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: white;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: center;
        }

        th {
            background-color: #f2f2f2;
            color: #333;
            font-weight: bold;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tr:hover {
            background-color: #f0f7ff;
        }

        button {
            padding: 8px 15px;
            margin: 2px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        button[name="approve"], button[name="edit_course"], button[name="add_course"], button.add-time {
            background-color: #00a8ff;
            color: white;
        }

        button[name="approve"]:hover, button[name="edit_course"]:hover, button[name="add_course"]:hover, button.add-time:hover {
            background-color: #0090dd;
        }

        button[name="reject"], button[name="delete_course"] {
            background-color: #ff6b6b;
            color: white;
        }

        button[name="reject"]:hover, button[name="delete_course"]:hover {
            background-color: #ff5252;
        }

        .search-form button {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
        }

        .search-form button[type="submit"] {
            background-color: #00a8ff;
            color: #fff;
        }

        .search-form button[type="submit"]:hover {
            background-color: #0090dd;
        }

        .search-form .reset-button {
            background-color: #f2f2f2;
            color: #333;
        }
        
        .search-form .reset-button:hover {
            background-color: #e0e0e0;
        }

        .bulk-buttons {
            margin-top: 10px;
            text-align: center;
        }

        .bulk-buttons button {
            padding: 8px 20px;
            margin: 0 5px;
        }

        .bulk-approve {
            background-color: #00a8ff;
            color: white;
        }

        .bulk-approve:hover {
            background-color: #0090dd;
        }

        .bulk-reject {
            background-color: #ff6b6b;
            color: white;
        }

        .bulk-reject:hover {
            background-color: #ff5252;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
        }

        .professorInfo {
            font-size: 14px;
            color: #666;
        }

        .logoutButton, .mypageButton {
            padding: 8px 15px;
            background-color: #f2f2f2;
            color: #333;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        .logoutButton:hover, .mypageButton:hover {
            background-color: #e0e0e0;
        }

        .noUser {
            text-align: center;
            padding: 30px;
            color: #888;
            font-size: 16px;
        }

        .search-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .search-form {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
        }

        .search-form input[type="text"] {
            padding: 8px;
            width: 200px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .search-form button {
            padding: 8px 15px;
            background-color: #00a8ff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .search-form button:hover {
            background-color: #0090dd;
        }

        .info-text {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            color: #666;
        }

        .info-text img {
            width: 16px;
            height: 16px;
        }

        .edit-form {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .edit-form input[type="text"], .edit-form input[type="number"], .edit-form select {
            padding: 8px;
            margin: 5px 0;
            width: calc(100% - 16px);
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        /* 모달창 스타일 */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-content h3 {
            margin-bottom: 20px;
            text-align: center;
        }

        .modal-content .close {
            float: right;
            font-size: 24px;
            cursor: pointer;
        }

        .modal-content label {
            display: block;
            margin: 10px 0 5px;
        }

        .modal-content input[type="text"], .modal-content input[type="number"], .modal-content select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .time-entry {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #eee;
            border-radius: 5px;
        }

        .time-entry select {
            width: 30%;
            margin-right: 5%;
            display: inline-block;
        }

        .course-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .course-list-header {
            margin-bottom: 20px; 
            overflow: auto; 
        }

        .course-list-title {
            text-align: center;
            margin-bottom: 10px; 
            font-size: 22px; 
            color: #333;
        }

        .add-course-btn {
            float: right;
            background-color: #00a8ff;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .add-course-btn:hover {
            background-color: #0090dd;
        }
    </style>
</head>
<body>
    <!-- 빌넣요청 관리 UI 렌더링 -->
    <div class="section">
        <div class="logo">
            <img src="https://blog.kakaocdn.net/dn/bx64Eo/btqEOZOpwoE/veAdLIDj4xKXMakWfvHRmk/img.jpg" alt="순천향대학교 로고">
        </div>

        <div class="header">
            <div class="professorInfo">
                <strong><?= htmlspecialchars($professorInfo['userName']) ?></strong> 교수님 환영합니다
            </div>
            <div class="header-buttons">
                <a href="myPage.php" class="mypageButton">마이페이지</a>
                <a href="login.php" class="logoutButton">로그아웃</a>
            </div>
        </div>

        <h2>빌넣요청 관리</h2>

        <!-- 검색 컨테이너 추가 -->
        <div class="search-container">
            <div class="info-text">
                <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNiIgaGVpZ2h0PSIxNiIgdmlld0JveD0iMCAwIDE2IDE2IiBmaWxsPSIjOTk5Ij48cGF0aCBkPSJNOCAxQzQuMTQgMSAxIDQuMTQgMSA4QzEgMTEuODYgNC4xNCAxNSA4IDE1QzExLjg2IDE1IDE1IDExLjg2IDE1IDhDMTUgNC4xNCAxMS44NiAxIDggMU0gOCAxNkM0LjY4NiAxNiAyIDE0LjMxNCAyIDEwQzIgNS42ODYgNC42ODYgMiA4IDJDMTEuMzEzIDIgMTQgNS42ODYgMTQgMTBDMTQgMTQuMzE0IDExLjMxMyAxNiA4IDE2Ij48L3BhdGg+PHBhdGggZD0iTTcgM0g5VjlIN1YzWk0gNyAxMUg5VjEzSDdWMTFaIj48L3BhdGg+PC9zdmc+" alt="정보">
                사유를 키워드로 검색할 수 있습니다.
            </div>
            <div class="search-form">
                <form method="get" id="profSearchForm">
                    <input type="text" name="search" placeholder="사유로 검색" 
                        value="<?= htmlspecialchars($searchKeyword) ?>">
                    <button type="submit">검색</button>
                    <button type="button" class="reset-button" onclick="resetSearch()">초기화</button>
                </form>
            </div>
        </div>

        <?php
        if ($result->num_rows > 0) {
        ?>
            <form method="post" id="bulkForm">
                <table>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>학번</th>
                        <th>이름</th>
                        <th>과목코드</th>
                        <th>과목명</th>
                        <th>사유</th>
                        <th>상태</th>
                        <th>관리</th>
                    </tr>

                    <?php
                    while ($row = $result->fetch_assoc()) {
                    ?>
                        <tr>
                            <td><input type="checkbox" name="selected_items[]" value="<?= htmlspecialchars($row["userID"]) . "_" . htmlspecialchars($row["courseID"]) ?>"></td>
                            <td><?= htmlspecialchars($row["userID"]) ?></td>
                            <td><?= htmlspecialchars($row["userName"]) ?></td>
                            <td><?= htmlspecialchars($row["courseID"]) ?></td>
                            <td><?= htmlspecialchars($row["courseName"]) ?></td>
                            <td><?= htmlspecialchars($row["reason"]) ?></td>
                            <td><?= htmlspecialchars($row["extraEnrollStatus"]) ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="extraEnrollID" value="<?= htmlspecialchars($row["extraEnrollID"]) ?>">
                                    <input type="hidden" name="userID" value="<?= htmlspecialchars($row["userID"]) ?>">
                                    <input type="hidden" name="courseID" value="<?= htmlspecialchars($row["courseID"]) ?>">
                                    <button type="submit" name="approve">승인</button>
                                    <button type="submit" name="reject">거절</button>
                                </form>
                            </td>
                        </tr>
                    <?php
                    }
                    $result->free(); // 결과셋 해제
                    ?>
                </table>
                <div class="bulk-buttons">
                    <button type="submit" name="bulk_approve" class="bulk-approve" onclick="return confirm('선택한 항목을 일괄 승인하시겠습니까?');">일괄 승인</button>
                    <button type="submit" name="bulk_reject" class="bulk-reject" onclick="return confirm('선택한 항목을 일괄 거절하시겠습니까?');">일괄 거절</button>
                </div>
            </form>
        <?php
        } else {
        ?>
            <div class="noUser">
                대기 중인 빌넣요청이 없습니다.
            </div>
        <?php
        }
        ?>

        <!-- 강의 목록 관리 -->
        <div class="course-list-header">
            <h2 class="course-list-title">내 강의 목록</h2>
            <button type="button" name="add_course_button" class="add-course-btn" onclick="openModal()">강의 추가</button>
        </div>
        <?php
        if ($courseResult->num_rows > 0) {
        ?>
            <table>
                <tr>
                    <th>과목코드</th>
                    <th>과목명</th>
                    <th>현재 수강인원</th>
                    <th>최대 수강인원</th>
                    <th>관리</th>
                </tr>
                <?php
                while ($course = $courseResult->fetch_assoc()) {
                    $showEditForm = isset($_GET['edit_course_id']) && $_GET['edit_course_id'] === $course['courseID'];
                ?>
                    <tr>
                        <td><?= htmlspecialchars($course['courseID']) ?></td>
                        <td><?= htmlspecialchars($course['courseName']) ?></td>
                        <td><?= htmlspecialchars($course['currentEnrollment']) ?></td>
                        <td><?= htmlspecialchars($course['capacity']) ?></td>
                        <td>
                            <form method="get" style="display:inline;">
                                <input type="hidden" name="edit_course_id" value="<?= htmlspecialchars($course['courseID']) ?>">
                                <button type="submit" name="edit">수정</button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('정말로 이 강의를 삭제하시겠습니까?');">
                                <input type="hidden" name="courseID" value="<?= htmlspecialchars($course['courseID']) ?>">
                                <button type="submit" name="delete_course">삭제</button>
                            </form>
                        </td>
                    </tr>
                    <?php if ($showEditForm) { ?>
                        <tr>
                            <td colspan="5">
                                <div class="edit-form">
                                    <form method="post">
                                        <input type="hidden" name="courseID" value="<?= htmlspecialchars($course['courseID']) ?>">
                                        <label>과목명:</label><br>
                                        <input type="text" name="courseName" value="<?= htmlspecialchars($course['courseName']) ?>" required><br>
                                        <label>최대 수강인원:</label><br>
                                        <input type="number" name="capacity" value="<?= htmlspecialchars($course['capacity']) ?>" min="1" required><br>
                                        <button type="submit" name="edit_course">저장</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                <?php
                }
                $courseResult->free(); // 결과셋 해제
                ?>
            </table>
        <?php
        } else {
        ?>
            <div class="noUser">
                담당 중인 강의가 없습니다.
            </div>
        <?php
        }
        ?>

        <!-- 강의 추가 모달 -->
        <div id="addCourseModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">×</span>
                <h3>강의 추가</h3>
                <form method="post" id="addCourseForm">
                    <label for="courseID">과목코드:</label>
                    <input type="text" name="courseID" required pattern="[A-Za-z0-9]{5}" title="과목코드는 5자리 영문/숫자 조합이어야 합니다.">
                    <label for="courseName">과목명:</label>
                    <input type="text" name="courseName" required>
                    <label for="classroom">강의실:</label>
                    <input type="text" name="classroom">
                    <label for="creditType">이수구분:</label>
                    <select name="creditType" required>
                        <option value="" disabled selected>선택</option>
                        <option value="전공">전공</option>
                        <option value="교양">교양</option>
                        <option value="기초">학부(과)기초</option>
                    </select>
                    <label for="area">영역:</label>
                    <select name="area" required>
                        <option value="" disabled selected>선택</option>
                        <option value="NULL">없음</option>
                        <?php foreach ($areas as $area_item) { ?>
                            <option value="<?= htmlspecialchars($area_item) ?>"><?= htmlspecialchars($area_item) ?></option>
                        <?php } ?>
                    </select>
                    <label for="grade">학년:</label>
                    <select name="grade" required>
                        <option value="" disabled selected>선택</option>
                        <option value="1학년">1학년</option>
                        <option value="2학년">2학년</option>
                        <option value="3학년">3학년</option>
                        <option value="4학년">4학년</option>
                    </select>
                    <label for="departmentID">학과:</label>
                    <select name="departmentID" required>
                        <option value="" disabled selected>선택</option>
                        <?php while ($dept = $deptResult->fetch_assoc()) { ?>
                            <option value="<?= htmlspecialchars($dept['departmentID']) ?>"><?= htmlspecialchars($dept['departmentName']) ?></option>
                        <?php } $deptResult->data_seek(0); ?>
                    </select>
                    <label for="credits">학점:</label>
                    <input type="number" name="credits" min="1" max="4" required>
                    <label for="capacity">최대 수강인원:</label>
                    <input type="number" name="capacity" min="1" required>
                    <label>강의 시간:</label>
                    <div id="timeEntries">
                        <div class="time-entry">
                            <select name="times[0][dayOfWeek]" required>
                                <option value="" disabled selected>요일</option>
                                <option value="월">월</option>
                                <option value="화">화</option>
                                <option value="수">수</option>
                                <option value="목">목</option>
                                <option value="금">금</option>
                            </select>
                            <select name="times[0][startPeriod]" required>
                                <option value="" disabled selected>시작 교시</option>
                                <option value="1">1교시</option>
                                <option value="1A">1A</option>
                                <option value="1B">1B</option>                                
                                <option value="2">2교시</option>
                                <option value="2A">2A</option>
                                <option value="2B">2B</option>
                                <option value="3">3교시</option>
                                <option value="3A">3A</option>
                                <option value="3B">3B</option>
                                <option value="4">4교시</option>
                                <option value="4A">4A</option>
                                <option value="4B">4B</option>
                                <option value="5">5교시</option>
                                <option value="5A">5A</option>
                                <option value="5B">5B</option>
                                <option value="6">6교시</option>
                                <option value="6A">6A</option>
                                <option value="6B">6B</option>
                                <option value="7">7교시</option>
                                <option value="7A">7A</option>
                                <option value="7B">7B</option>
                                <option value="8">8교시</option>
                                <option value="8A">8A</option>
                                <option value="8B">8B</option>
                                <option value="9">9교시</option>
                                <option value="9A">9A</option>
                                <option value="9B">9B</option>
                            </select>
                            <select name="times[0][endPeriod]" required>
                                <option value="" disabled selected>종료 교시</option>
                                <option value="1">1교시</option>
                                <option value="1A">1A</option>
                                <option value="1B">1B</option>                                
                                <option value="2">2교시</option>
                                <option value="2A">2A</option>
                                <option value="2B">2B</option>
                                <option value="3">3교시</option>
                                <option value="3A">3A</option>
                                <option value="3B">3B</option>
                                <option value="4">4교시</option>
                                <option value="4A">4A</option>
                                <option value="4B">4B</option>
                                <option value="5">5교시</option>
                                <option value="5A">5A</option>
                                <option value="5B">5B</option>
                                <option value="6">6교시</option>
                                <option value="6A">6A</option>
                                <option value="6B">6B</option>
                                <option value="7">7교시</option>
                                <option value="7A">7A</option>
                                <option value="7B">7B</option>
                                <option value="8">8교시</option>
                                <option value="8A">8A</option>
                                <option value="8B">8B</option>
                                <option value="9">9교시</option>
                                <option value="9A">9A</option>
                                <option value="9B">9B</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="add-time" onclick="addTimeEntry()">시간 추가</button>
                    <button type="submit" name="add_course">저장</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // 전체 선택 체크박스 처리
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="selected_items[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // 검색 초기화
        function resetSearch() {
            window.location.href = window.location.pathname;
        }

        // 모달창 열기
        function openModal() {
            const modal = document.getElementById('addCourseModal');
            modal.style.display = 'flex';
        }

        // 모달창 닫기
        function closeModal() {
            const modal = document.getElementById('addCourseModal');
            modal.style.display = 'none';
            document.getElementById('addCourseForm').reset();
            const timeEntries = document.getElementById('timeEntries');
            while (timeEntries.children.length > 1) {
                timeEntries.removeChild(timeEntries.lastChild);
            }
            timeEntryCount = 1; // 시간 입력 카운터 초기화
        }

        // 모달 외부 클릭 시 닫기
        window.onclick = function(event) {
            const modal = document.getElementById('addCourseModal');
            const modalContent = document.querySelector('.modal-content');
            if (event.target === modal && !modalContent.contains(event.target)) {
                closeModal();
            }
        }

        // 시간 입력 추가
        let timeEntryCount = 1;
        function addTimeEntry() {
            const timeEntries = document.getElementById('timeEntries');
            const newEntry = document.createElement('div');
            newEntry.className = 'time-entry';
            newEntry.innerHTML = `
                <select name="times[${timeEntryCount}][dayOfWeek]" required>
                    <option value="" disabled selected>요일</option>
                    <option value="월">월</option>
                    <option value="화">화</option>
                    <option value="수">수</option>
                    <option value="목">목</option>
                    <option value="금">금</option>
                </select>
                <select name="times[${timeEntryCount}][startPeriod]" required>
                    <option value="" disabled selected>시작 교시</option>
                    <option value="1">1교시</option>
                    <option value="1A">1A</option>
                    <option value="1B">1B</option>
                    <option value="2">2교시</option>
                    <option value="2A">2A</option>
                    <option value="2B">2B</option>
                    <option value="3">3교시</option>
                    <option value="3A">3A</option>
                    <option value="3B">3B</option>
                    <option value="4">4교시</option>
                    <option value="4A">4A</option>
                    <option value="4B">4B</option>
                    <option value="5">5교시</option>
                    <option value="5A">5A</option>
                    <option value="5B">5B</option>
                    <option value="6">6교시</option>
                    <option value="6A">6A</option>
                    <option value="6B">6B</option>
                    <option value="7">7교시</option>
                    <option value="7A">7A</option>
                    <option value="7B">7B</option>
                    <option value="8">8교시</option>
                    <option value="8A">8A</option>
                    <option value="8B">8B</option>
                    <option value="9">9교시</option>
                    <option value="9A">9A</option>
                    <option value="9B">9B</option>
                </select>
                <select name="times[${timeEntryCount}][endPeriod]" required>
                    <option value="" disabled selected>종료 교시</option>
                    <option value="1">1교시</option>
                    <option value="1A">1A</option>
                    <option value="1B">1B</option>
                    <option value="2">2교시</option>
                    <option value="2A">2A</option>
                    <option value="2B">2B</option>
                    <option value="3">3교시</option>
                    <option value="3A">3A</option>
                    <option value="3B">3B</option>
                    <option value="4">4교시</option>
                    <option value="4A">4A</option>
                    <option value="4B">4B</option>
                    <option value="5">5교시</option>
                    <option value="5A">5A</option>
                    <option value="5B">5B</option>
                    <option value="6">6교시</option>
                    <option value="6A">6A</option>
                    <option value="6B">6B</option>
                    <option value="7">7교시</option>
                    <option value="7A">7A</option>
                    <option value="7B">7B</option>
                    <option value="8">8교시</option>
                    <option value="8A">8A</option>
                    <option value="8B">8B</option>
                    <option value="9">9교시</option>
                    <option value="9A">9A</option>
                    <option value="9B">9B</option>
                </select>
            `;
            timeEntries.appendChild(newEntry);
            timeEntryCount++;
        }

        // 폼 제출 시 클라이언트 측 검증
        document.getElementById('addCourseForm').addEventListener('submit', function(event) {
            const courseID = document.querySelector('input[name="courseID"]').value.trim();
            const courseName = document.querySelector('input[name="courseName"]').value.trim();
            const creditType = document.querySelector('select[name="creditType"]').value;
            const area = document.querySelector('select[name="area"]').value;
            const grade = document.querySelector('select[name="grade"]').value;
            const departmentID = document.querySelector('select[name="departmentID"]').value;
            const credits = document.querySelector('input[name="credits"]').value;
            const capacity = document.querySelector('input[name="capacity"]').value;
            const timeEntries = document.querySelectorAll('.time-entry');
            let validTimes = false;

            // courseID 형식 검증
            if (!/^[A-Za-z0-9]{5}$/.test(courseID)) {
                event.preventDefault();
                alert('과목코드는 5자리 영문/숫자 조합이어야 합니다.');
                return;
            }

            // 시간 항목 검증
            for (let entry of timeEntries) {
                const day = entry.querySelector('select[name*="[dayOfWeek]"]').value;
                const start = entry.querySelector('select[name*="[startPeriod]"]').value;
                const end = entry.querySelector('select[name*="[endPeriod]"]').value;
                if (day && start && end) {
                    validTimes = true;
                    // 클라이언트 측 시간 순서 검증
                    const startNum = parseInt(start.replace(/[A-B]/, '')) || 0;
                    const endNum = parseInt(end.replace(/[A-B]/, '')) || 0;
                    const startLetter = start.match(/[A-B]/)?.[0] || '';
                    const endLetter = end.match(/[A-B]/)?.[0] || '';
                    if (startNum > endNum || (startNum === endNum && startLetter === 'B' && endLetter === 'A')) {
                        event.preventDefault();
                        alert('종료 교시가 시작 교시보다 빠를 수 없습니다.');
                        return;
                    }
                }
            }

            if (!courseID || !courseName || !creditType || !area || !grade || !departmentID || !credits || !capacity || !validTimes) {
                event.preventDefault();
                alert('모든 필수 필드를 올바르게 입력해주세요.');
            }
        });
    </script>
</body>
</html>
<?php
$conn->close();
if ($deptResult) {
    $deptResult->close();
}
?>