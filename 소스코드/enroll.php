<?php
// ---------------------------------------
// 초기 설정
// ---------------------------------------

header('Content-Type: text/html; charset=UTF-8'); // UTF-8 인코딩 설정

session_start();

// 사용자가 로그인되어 있고 학생인지 확인
if (!isset($_SESSION["userID"]) || $_SESSION["userRole"] !== 'student')
{
    // 로그인 페이지로 리다이렉트
    header("Location: login.php");
    exit();
}

// student_user로 접속
$conn = new mysqli("localhost", "student_user", "StudentPass123!", "dbproject");
if ($conn->connect_error)
{
    die("DB 연결 실패: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// 요일 매핑
$daysKorean = [
    'Mon' => '월',
    'Tue' => '화',
    'Wed' => '수',
    'Thu' => '목',
    'Fri' => '금',
    'Sat' => '토'
];

$daysEnglish = [
    '월' => 'Mon',
    '화' => 'Tue',
    '수' => 'Wed',
    '목' => 'Thu',
    '금' => 'Fri',
    '토' => 'Sat'
];

// ---------------------------------------
// 데이터 조회
// ---------------------------------------

// 학생 정보 가져오기
$studentID = $_SESSION["userID"];
$studentQuery = "SELECT u.userID, u.userName, u.grade, u.lastSemesterCredits, u.userRole, 
                        u.departmentID, d.departmentName, c.collegeName 
                FROM User u 
                LEFT JOIN Department d ON u.departmentID = d.departmentID
                LEFT JOIN College c ON d.collegeID = c.collegeID
                WHERE u.userID = ?";
$stmt = $conn->prepare($studentQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$studentResult = $stmt->get_result();
$studentInfo = $studentResult->fetch_assoc();
$stmt->close();

// Course 테이블에서 area 목록 가져오기
$areaQuery = "SELECT DISTINCT area FROM Course WHERE area IS NOT NULL AND area != '' ORDER BY area";
$areaResult = $conn->query($areaQuery);
$areas = [];
if ($areaResult)
{
    while ($row = $areaResult->fetch_assoc())
    {
        $areas[] = $row['area'];
    }
    $areaResult->free();
}
// (중핵) 하단으로 정렬
$areas_normal = [];
$areas_core = [];
foreach ($areas as $area_item)
{
    if (strpos($area_item, '(중핵)') !== false)
    {
        $areas_core[] = $area_item;
    }
    else
    {
        $areas_normal[] = $area_item;
    }
}
// 기본 정렬 후 (중핵) 항목을 뒤에 붙임
sort($areas_normal); // 일반 항목 가나다순 정렬
sort($areas_core);   // (중핵) 항목 가나다순 정렬
$areas = array_merge($areas_normal, $areas_core); // 두 배열을 합침

// 검색 드롭다운을 위한 모든 단과대학 조회
$collegesQuery = "SELECT * FROM College ORDER BY collegeName";
$colleges = $conn->query($collegesQuery);
$colleges_arr_for_js = []; // JS 전달용 배열
if ($colleges && $colleges->num_rows > 0)
{
    $colleges_arr_for_js = $colleges->fetch_all(MYSQLI_ASSOC);
    $colleges->data_seek(0); // HTML 생성을 위해 포인터 리셋
}

// 검색 드롭다운을 위한 모든 학과 조회
$departmentsQuery = "SELECT d.*, c.collegeName FROM Department d 
                     JOIN College c ON d.collegeID = c.collegeID 
                     ORDER BY c.collegeName, d.departmentName";
$departments = $conn->query($departmentsQuery);
$departments_arr_for_js = []; // JS 전달용 배열
if ($departments && $departments->num_rows > 0)
{
    $departments_arr_for_js = $departments->fetch_all(MYSQLI_ASSOC);
    $departments->data_seek(0); // HTML 생성을 위해 포인터 리셋
}

// 수강신청 내역 가져오기 (중복 제거를 위해 CourseTime을 별도로 처리)
$enrolledQuery = "SELECT e.*, c.courseName, c.credits, u.userName as professor, c.creditType
                FROM Enroll e
                JOIN Course c ON e.courseID = c.courseID
                LEFT JOIN User u ON c.professorID = u.userID
                WHERE e.userID = ?";
$stmt = $conn->prepare($enrolledQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$enrolledCourses = $stmt->get_result();
$stmt->close();

// CourseTime 데이터를 별도로 가져오기
$courseTimesQuery = "SELECT ct.courseID, ct.dayOfWeek, ct.startPeriod, ct.endPeriod
                     FROM CourseTime ct
                     WHERE ct.courseID IN (SELECT courseID FROM Enroll WHERE userID = ?)";
$stmt = $conn->prepare($courseTimesQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$courseTimesResult = $stmt->get_result();
$stmt->close();

// ---------------------------------------
// 데이터 처리
// ---------------------------------------

// CourseTime 데이터를 courseID별로 그룹화
$courseTimes = [];
while ($time = $courseTimesResult->fetch_assoc())
{
    $courseTimes[$time['courseID']][] = $time;
}

// 총 학점 및 시간표 데이터 초기화
$totalCredits = 0;
$totalCourses = 0;
$timeTable = array(); // 시간표 데이터를 저장하기 위한 배열
$courseColors = array(); // 강의별 색상 생성을 위한 배열

// 수강신청 내역에서 총 학점 계산 및 시간표 데이터 생성
if ($enrolledCourses->num_rows > 0)
{
    $totalCourses = $enrolledCourses->num_rows;
    while ($course = $enrolledCourses->fetch_assoc())
    {
        $totalCredits += $course['credits'];
        
        // courseID를 기반으로 고유한 색상 생성
        if (!isset($courseColors[$course['courseID']]))
        {
            // courseID를 해시하여 Hue 값 생성 (0~360)
            $hash = crc32($course['courseID']);
            $hue = $hash % 360; // 0~359 사이의 Hue 값
            $saturation = 60; // 채도 60%
            $lightness = 50;  // 밝기 50% (가독성을 위해 너무 어둡거나 밝지 않게)
            $courseColors[$course['courseID']] = "hsl($hue, $saturation%, $lightness%)";
        }

        // 시간표 데이터 저장 (A, B 구분 포함)
        if (isset($courseTimes[$course['courseID']]))
        {
            foreach ($courseTimes[$course['courseID']] as $time)
            {
                $day = $time['dayOfWeek'];
                $start = $time['startPeriod'];
                $end = $time['endPeriod'];

                // A/B가 포함된 경우와 숫자만 있는 경우 구분
                $startNum = (int)preg_replace('/[AB]/', '', $start);
                $endNum = (int)preg_replace('/[AB]/', '', $end);
                $startAB = preg_match('/[AB]/', $start) ? substr($start, -1) : '';
                $endAB = preg_match('/[AB]/', $end) ? substr($end, -1) : '';

                if ($startAB === '' && $endAB === '')
                {
                    // 숫자만 있는 경우: A와 B 모두 표시
                    for ($period = $startNum; $period <= $endNum; $period++)
                    {
                        $timeTable[$day][$period]['A'] = [
                            'courseName' => $course['courseName'],
                            'courseID' => $course['courseID']
                        ];
                        $timeTable[$day][$period]['B'] = [
                            'courseName' => $course['courseName'],
                            'courseID' => $course['courseID']
                        ];
                    }
                }
                else
                {
                    // A/B가 명시된 경우: startAB에서 endAB까지 표시
                    for ($period = $startNum; $period <= $endNum; $period++)
                    {
                        if ($period == $startNum)
                        {
                            // 시작 교시: startAB에 따라 A 또는 B 시작
                            if ($startAB == 'A' || $startAB == '')
                            {
                                $timeTable[$day][$period]['A'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                            if ($startAB == 'B' || $startAB == '' || $startAB == 'A')
                            {
                                $timeTable[$day][$period]['B'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                        }
                        elseif ($period == $endNum)
                        {
                            // 종료 교시: endAB에 따라 A 또는 B 끝
                            if ($endAB == 'A' || $endAB == '')
                            {
                                $timeTable[$day][$period]['A'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                            if ($endAB == 'B' || $endAB == '')
                            {
                                $timeTable[$day][$period]['B'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                            // 종료 교시에서도 A를 포함 (B-B 구간에서 중간 교시처럼 처리)
                            if ($endAB == 'B' && $startAB == 'B' && $period > $startNum)
                            {
                                $timeTable[$day][$period]['A'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                        }
                        else
                        {
                            // 중간 교시: A와 B 모두 표시
                            $timeTable[$day][$period]['A'] = [
                                'courseName' => $course['courseName'],
                                'courseID' => $course['courseID']
                            ];
                            $timeTable[$day][$period]['B'] = [
                                'courseName' => $course['courseName'],
                                'courseID' => $course['courseID']
                            ];
                        }
                    }
                }
            }
        }
    }
    
    // 쿼리 결과를 다시 얻기 위해 쿼리를 재실행
    $stmt = $conn->prepare($enrolledQuery);
    $stmt->bind_param("s", $studentID);
    $stmt->execute();
    $enrolledCourses = $stmt->get_result();
    $stmt->close();
}

// 한 학기 최대 신청 가능 학점 - lastSemesterCredits에 따라 다르게 설정
$maxCredits = ($studentInfo['lastSemesterCredits'] >= 3.0) ? 19 : 18;

// ---------------------------------------
// 수강신청 처리 로직
// ---------------------------------------

// 수강신청 처리 함수 (트랜잭션 추가)
function enrollCourse($conn, $studentID, $courseID, $totalCredits, $timeTable, $studentInfo)
{
    // 1. 과목코드 유효성 확인
    $courseCheckQuery = "SELECT courseID, credits, capacity, currentEnrollment FROM Course WHERE courseID = ?";
    $stmt = $conn->prepare($courseCheckQuery);
    $stmt->bind_param("s", $courseID);
    $stmt->execute();
    $courseResult = $stmt->get_result();
    
    if ($courseResult->num_rows === 0)
    {
        return "존재하지 않는 과목코드입니다.";
    }
    
    $course = $courseResult->fetch_assoc();
    $stmt->close();
    
    // 2. 이미 수강신청했는지 확인
    $alreadyEnrolledQuery = "SELECT * FROM Enroll WHERE userID = ? AND courseID = ?";
    $stmt = $conn->prepare($alreadyEnrolledQuery);
    $stmt->bind_param("ss", $studentID, $courseID);
    $stmt->execute();
    $alreadyEnrolledResult = $stmt->get_result();
    if ($alreadyEnrolledResult->num_rows > 0)
    {
        return "이미 수강신청한 과목입니다.";
    }
    $stmt->close();
    
    // 3. 정원 확인
    if ($course['currentEnrollment'] >= $course['capacity'])
    {
        return "정원이 가득 찼습니다. 빌넣 요청을 이용해주세요.";
    }
    
    // 4. 학점 초과 확인
    $newTotalCredits = $totalCredits + $course['credits'];
    $maxCredits = ($studentInfo['lastSemesterCredits'] >= 3.0) ? 19 : 18;
    if ($newTotalCredits > $maxCredits)
    {
        return "최대 신청 가능 학점을 초과했습니다. (최대: $maxCredits 학점, 현재: $totalCredits 학점, 추가 시도: {$course['credits']} 학점)";
    }
    
    // 5. 시간표 충돌 확인
    $newCourseTimesQuery = "SELECT dayOfWeek, startPeriod, endPeriod FROM CourseTime WHERE courseID = ?";
    $stmt = $conn->prepare($newCourseTimesQuery);
    $stmt->bind_param("s", $courseID);
    $stmt->execute();
    $newCourseTimesResult = $stmt->get_result();
    $newCourseTimes = [];
    while ($time = $newCourseTimesResult->fetch_assoc())
    {
        $newCourseTimes[] = $time;
    }
    $stmt->close();
    
    $conflict = false;
    foreach ($newCourseTimes as $newTime)
    {
        $day = $newTime['dayOfWeek'];
        $start = (int)preg_replace('/[AB]/', '', $newTime['startPeriod']);
        $end = (int)preg_replace('/[AB]/', '', $newTime['endPeriod']);
        $startAB = preg_match('/[AB]/', $newTime['startPeriod']) ? substr($newTime['startPeriod'], -1) : '';
        $endAB = preg_match('/[AB]/', $newTime['endPeriod']) ? substr($newTime['endPeriod'], -1) : '';
        
        for ($period = $start; $period <= $end; $period++)
        {
            $slotsToCheck = [];
            if ($startAB === '' && $endAB === '')
            {
                $slotsToCheck = ['A', 'B'];
            }
            elseif ($startAB === 'A' && $endAB === 'A')
            {
                $slotsToCheck = ['A'];
            }
            elseif ($startAB === 'B' && $endAB === 'B')
            {
                $slotsToCheck = ['B'];
            }
            elseif ($startAB === 'A' && $endAB === 'B')
            {
                $slotsToCheck = ($period === $start) ? ['A'] : (($period === $end) ? ['B'] : ['A', 'B']);
            }
            elseif ($startAB === 'B' && $endAB === 'A')
            {
                $slotsToCheck = ($period === $start) ? ['B'] : (($period === $end) ? ['A'] : ['A', 'B']);
            }
            
            foreach ($slotsToCheck as $slot)
            {
                if (isset($timeTable[$day][$period][$slot]))
                {
                    $conflict = true;
                    break 3; // 충돌 발견 시 모든 루프 탈출
                }
            }
        }
    }
    
    if ($conflict)
    {
        return "시간표가 충돌합니다. 다른 강의를 선택해주세요.";
    }
    
    // 6. 수강신청 등록 (트랜잭션 시작)
    $conn->begin_transaction();
    try
    {
        // Enroll 테이블에 삽입
        $enrollQuery = "INSERT INTO Enroll (userID, courseID) VALUES (?, ?)";
        $stmt = $conn->prepare($enrollQuery);
        $stmt->bind_param("ss", $studentID, $courseID);
        $enrollSuccess = $stmt->execute();
        if (!$enrollSuccess)
        {
            throw new Exception("Enroll 삽입 실패: " . $conn->error);
        }
        $stmt->close();
        
        // currentEnrollment 증가
        $updateEnrollmentQuery = "UPDATE Course SET currentEnrollment = currentEnrollment + 1 WHERE courseID = ?";
        $stmt = $conn->prepare($updateEnrollmentQuery);
        $stmt->bind_param("s", $courseID);
        $updateSuccess = $stmt->execute();
        if (!$updateSuccess)
        {
            throw new Exception("currentEnrollment 업데이트 실패: " . $stmt->error);
        }
        $stmt->close();
        
        // 트랜잭션 커밋
        $conn->commit();
        return true;
    }
    catch (Exception $e)
    {
        $conn->rollback();
        return "수강신청 중 오류가 발생했습니다: " . htmlspecialchars($e->getMessage());
    }
}

// 수강신청 취소 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['courseID']) && isset($_POST['action']) && $_POST['action'] === 'cancel')
{
    $deleteCourseID = $_POST['courseID'];

    // 트랜잭션 시작
    $conn->begin_transaction();

    try
    {
        // Enroll 테이블에서 삭제
        $stmt = $conn->prepare("DELETE FROM Enroll WHERE userID = ? AND courseID = ?");
        $stmt->bind_param("ss", $studentID, $deleteCourseID);
        $success = $stmt->execute();
        if (!$success)
        {
            throw new Exception("Enroll 삭제 실패: " . $conn->error);
        }
        $stmt->close();

        // ExtraEnroll 테이블에서 해당 데이터 삭제
        $stmt = $conn->prepare("DELETE FROM ExtraEnroll WHERE userID = ? AND courseID = ?");
        $stmt->bind_param("ss", $studentID, $deleteCourseID);
        $success = $stmt->execute();
        if (!$success)
        {
            throw new Exception("ExtraEnroll 삭제 실패: " . $conn->error);
        }
        $stmt->close();

        // 강의 현재 수강신청 인원 감소
        $stmt = $conn->prepare("UPDATE Course SET currentEnrollment = GREATEST(currentEnrollment - 1, 0) WHERE courseID = ?");
        $stmt->bind_param("s", $deleteCourseID);
        $success = $stmt->execute();
        if (!$success)
        {
            throw new Exception("currentEnrollment 업데이트 실패: " . $conn->error);
        }
        $stmt->close();

        // 트랜잭션 커밋
        $conn->commit();

        // 새로고침(POST-Redirect-GET 패턴)
        header("Location: enroll.php?" . time());
        exit();
    }
    catch (Exception $e)
    {
        $conn->rollback();
        echo "<script>alert('수강신청 취소 중 오류가 발생했습니다: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='enroll.php';</script>";
        exit();
    }
}

// 과목코드로 수강신청 처리 및 신청 버튼 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quickEnrollCourseID']) && isset($_POST['action']) && ($_POST['action'] === 'quickEnroll' || $_POST['action'] === 'enroll'))
{
    $quickCourseID = trim($_POST['quickEnrollCourseID']);
    
    // 현재 수강신청 학점을 다시 계산
    $totalCredits = 0;
    $enrolledCourses->data_seek(0); // 포인터 리셋
    while ($course = $enrolledCourses->fetch_assoc())
    {
        $totalCredits += $course['credits'];
    }
    $enrolledCourses->data_seek(0); // 포인터 다시 리셋
    
    $result = enrollCourse($conn, $studentID, $quickCourseID, $totalCredits, $timeTable, $studentInfo);
    
    if ($result === true)
    {
        echo "<script>alert('수강신청이 완료되었습니다.'); window.location.href='enroll.php';</script>";
        exit();
    }
    else
    {
        echo "<script>alert('$result'); window.location.href='enroll.php';</script>";
        exit();
    }
}

// ---------------------------------------
// 검색 로직
// ---------------------------------------

// 검색 결과 초기화
$searchResults = null;

// 검색 기능
if (isset($_GET['perform_search']) && $_GET['perform_search'] == '1')
{ // 'search' 대신 'perform_search' 사용 (폼 name과 구분)
    $searchType = isset($_GET['searchType']) ? $_GET['searchType'] : 'all';

    $baseQuery = "SELECT c.*, u.userName as professor, d.departmentName, 
                  GROUP_CONCAT(DISTINCT CONCAT(ct.dayOfWeek, ' ', ct.startPeriod, '-', ct.endPeriod) SEPARATOR ', ') as courseTimesFormatted
                  FROM Course c
                  LEFT JOIN User u ON c.professorID = u.userID
                  LEFT JOIN Department d ON c.departmentID = d.departmentID
                  LEFT JOIN CourseTime ct ON c.courseID = ct.courseID";
    $whereClauses = [];
    $params = [];
    $types = "";
    
    if ($searchType == 'cart')
    {
        $baseQuery .= " JOIN Cart cart_table ON c.courseID = cart_table.courseID";
        $whereClauses[] = "cart_table.userID = ?";
        $params[] = $studentID;
        $types .= "s";
    }
    elseif ($searchType == 'area')
    {
        if (!empty($_GET['detailSearch']))
        {
            $whereClauses[] = "c.area = ?";
            $params[] = $_GET['detailSearch'];
            $types .= "s";
        }
    }
    elseif ($searchType == 'college_department')
    {
        if (!empty($_GET['detailSearch']))
        { // 단과대학 ID
            $whereClauses[] = "d.collegeID = ?";
            $params[] = $_GET['detailSearch'];
            $types .= "i";
        }
        if (!empty($_GET['department']))
        { // 학과 ID
            $whereClauses[] = "c.departmentID = ?";
            $params[] = $_GET['department'];
            $types .= "i";
        }
    }
    // 'all' 타입은 별도 조건 없음

    // 키워드 검색
    if (!empty($_GET['keyword']))
    {
        $keyword = '%' . $_GET['keyword'] . '%';
        $whereClauses[] = "(c.courseName LIKE ? OR u.userName LIKE ?)";
        $params[] = $keyword;
        $params[] = $keyword;
        $types .= "ss";
    }

    $searchQuery = $baseQuery;
    if (!empty($whereClauses))
    {
        $searchQuery .= " WHERE " . implode(" AND ", $whereClauses);
    }
    $searchQuery .= " GROUP BY c.courseID ORDER BY c.courseID";

    $stmt_search = $conn->prepare($searchQuery);
    if ($stmt_search)
    {
        if (!empty($params))
        {
            $stmt_search->bind_param($types, ...$params);
        }
        $stmt_search->execute();
        $searchResults = $stmt_search->get_result();
    }
    else
    {
        echo "Error preparing statement: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>순천향대학교 수강신청 시스템 - 학생</title>
    <style>
        * 
        {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Malgun Gothic', sans-serif;
        }

        body
        {
            display: flex;
        }

        .sidebar
        {
            width: 200px;
            background-color: #2c3e50;
            color: white;
            height: 100vh;
            padding: 20px 0;
            position: fixed;
        }

        .sidebar ul
        {
            list-style: none;
            padding: 0;
        }

        .sidebar ul li
        {
            padding: 15px 20px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .sidebar ul li:hover
        {
            background-color: #34495e;
        }

        .sidebar ul li.active
        {
            background-color: #3498db;
        }

        .sidebar ul li a
        {
            color: white;
            text-decoration: none;
            font-size: 16px;
        }

        .content
        {
            margin-left: 220px;
            width: calc(100% - 220px);
            padding: 20px;
        }

        /* 기존 .section 스타일 수정 */
        .section
        {
            width: 100%;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-top: 0;
            margin-bottom: 30px;
        }

        .logo 
        {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo img 
        {
            width: 200px;
        }

        .header 
        {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .studentInfo 
        {
            font-size: 14px;
            color: #666;
        }

        .logoutButton 
        {
            padding: 8px 15px;
            background-color: #f2f2f2;
            color: #333;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        .logoutButton:hover 
        {
            background-color: #e0e0e0;
        }

        .creditInfo 
        {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 5px;
            border: 1px solid #eee;
        }

        .creditBox 
        {
            text-align: center;
            flex: 1;
        }

        .creditBox h3 
        {
            font-size: 14px;
            color: #555;
            margin-bottom: 5px;
        }

        .creditBox p 
        {
            font-size: 20px;
            color: #00a8ff;
            font-weight: bold;
        }

        .creditBox .maxCredit 
        {
            color: #ff6b6b;
        }

        .contentWrapper 
        {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .courseList 
        {
            flex: 2;
        }

        .timeTable 
        {
            flex: 1;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }

        .timeTable table 
        {
            width: 100%;
            border-collapse: collapse;
        }

        .timeTable th, .timeTable td
        {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
            font-size: 12px;
            height: 25px;
        }

        .timeTable th 
        {
            background-color: #f2f2f2;
        }

        .timeTable .time 
        {
            width: 30px;
            background-color: #f2f2f2;
            font-weight: bold;
        }

        .timeTable .course 
        {
            color: white;
            font-size: 11px;
        }

        /* 동적으로 생성된 강의 색상 클래스 */
        <?php
        foreach ($courseColors as $courseID => $color)
        {
            echo ".course-$courseID { background-color: $color; }\n";
        }
        ?>

        .searchSection 
        {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #eee;
        }

        .searchRow 
        {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }

        .searchRow label 
        {
            width: 120px;
            font-size: 14px;
            color: #555;
        }

        .searchRow select, .searchRow input 
        {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            flex: 1;
        }

        .buttonRow 
        {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        .button 
        {
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .searchButton 
        {
            background-color: #00a8ff;
            color: white;
        }

        .searchButton:hover 
        {
            background-color: #0090dd;
        }

        .resetButton 
        {
            background-color: #f2f2f2;
            color: #333;
        }

        .resetButton:hover 
        {
            background-color: #e0e0e0;
        }

        table 
        {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 14px;
        }

        table caption 
        {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            text-align: left;
            color: #333;
        }

        th, td 
        {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
        }

        th 
        {
            background-color: #f2f2f2;
            color: #333;
            font-weight: bold;
        }

        tr:nth-child(even) 
        {
            background-color: #f9f9f9;
        }

        tr:hover 
        {
            background-color: #f0f7ff;
        }

        .courseType 
        {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .required 
        {
            background-color: #00a8ff;
        }

        .elective 
        {
            background-color: #28a745;
        }

        .registerButton 
        {
            padding: 5px 10px;
            background-color: #00a8ff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .registerButton:hover 
        {
            background-color: #0090dd;
        }

        .deleteButton 
        {
            padding: 5px 10px;
            background-color: #ff6b6b;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .deleteButton:hover 
        {
            background-color: #ff5252;
        }

        .notification 
        {
            font-size: 12px;
            color: #666;
            margin-top: 10px;
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            justify-content: space-between; /* 문구와 입력창/버튼을 양쪽으로 배치 */
        }

        .notification img 
        {
            width: 16px;
            margin-right: 5px;
        }

        .quickEnrollContainer 
        {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quickEnrollContainer input 
        {
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 120px;
            font-size: 12px;
        }

        .quickEnrollContainer button 
        {
            padding: 5px 10px;
            background-color: #00a8ff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .quickEnrollContainer button:hover 
        {
            background-color: #0090dd;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <ul>
            <li><a href="cart.php">예비수강신청</a></li>
            <li class="active"><a href="enroll.php">수강신청</a></li>
            <li><a href="extraEnroll.php">빌넣요청</a></li>
        </ul>
    </div>
    <div class="content">
        <div class="section">
            <div class="logo">
                <img src="https://blog.kakaocdn.net/dn/bx64Eo/btqEOZOpwoE/veAdLIDj4xKXMakWfvHRmk/img.jpg" alt="순천향대학교 로고">
            </div>

            <div class="header">
                <div class="studentInfo">
                    <strong><?= htmlspecialchars($studentInfo['userName']) ?></strong> 님 환영합니다
                    <span>(학과: <?= htmlspecialchars($studentInfo['departmentName']) ?>, 학번: <?= htmlspecialchars($studentID) ?>)</span>
                </div>
                <a href="login.php" class="logoutButton">로그아웃</a>
            </div>

            <!-- 수강신청 현황 정보 -->
            <div class="creditInfo">
                <div class="creditBox">


                    <h3>현재 신청 학점</h3>
                    <p><?= $totalCredits ?> 학점</p>
                </div>
                <div class="creditBox">
                    <h3>신청 교과목 수</h3>
                    <p><?= $totalCourses ?> 과목</p>
                </div>
                <div class="creditBox">
                    <h3>신청 가능 학점</h3>
                    <p class="maxCredit"><?= $maxCredits ?> 학점</p>
                </div>
            </div>

            <!-- 수강신청 내역 및 시간표 -->
            <div class="contentWrapper">
                <div class="courseList">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>이수구분</th>
                                <th>과목코드</th>
                                <th>교과목명</th>
                                <th>교수명</th>
                                <th>학점</th>
                                <th>강의시간</th>
                                <th>관리</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rowNum = 1;
                            if ($enrolledCourses->num_rows > 0)
                            {
                                while ($course = $enrolledCourses->fetch_assoc())
                                { 
                                    // 강의 시간 포맷팅
                                    $timeSlots = [];
                                    if (isset($courseTimes[$course['courseID']]))
                                    {
                                        foreach ($courseTimes[$course['courseID']] as $time)
                                        {
                                            $day = isset($daysKorean[$time['dayOfWeek']]) ? $daysKorean[$time['dayOfWeek']] : $time['dayOfWeek'];
                                            $timeSlots[] = "$day {$time['startPeriod']}-{$time['endPeriod']}";
                                        }
                                    }
                                    $timeDisplay = implode('/', $timeSlots);
                            ?>
                            <tr>
                                <td><?= $rowNum++ ?></td>
                                <td><?= htmlspecialchars($course['creditType']) ?></td>
                                <td><?= htmlspecialchars($course['courseID']) ?></td>
                                <td><?= htmlspecialchars($course['courseName']) ?></td>
                                <td><?= htmlspecialchars($course['professor']) ?></td>
                                <td><?= htmlspecialchars($course['credits']) ?></td>
                                <td><?= htmlspecialchars($timeDisplay) ?></td>
                                <td>
                                    <form method="post" action="enroll.php" style="display:inline;" onsubmit="return confirm('정말로 이 강의를 취소하시겠습니까?');">
                                        <input type="hidden" name="courseID" value="<?= $course['courseID'] ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <button type="submit" class="deleteButton">취소</button>
                                    </form>
                                </td>
                            </tr>
                            <?php 
                                }
                            }
                            else
                            {
                            ?>
                            <tr>
                                <td colspan="8">수강신청 내역이 없습니다.</td>
                            </tr>
                            <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="timeTable">
                    <table>
                        <thead>
                            <tr>
                                <th></th>
                                <th>Mon</th>
                                <th>Tue</th>
                                <th>Wed</th>
                                <th>Thu</th>
                                <th>Fri</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // 시간표를 1교시부터 9교시까지 반복, 각 교시에 A와 B 추가
                            for ($i = 1; $i <= 9; $i++)
                            { 
                                // A row
                                echo "<tr>";
                                echo "<td class='time' rowspan='2'>$i</td>";
                                foreach (array('월', '화', '수', '목', '금') as $day)
                                {
                                    $dayEng = $daysEnglish[$day];
                                    echo "<td ";
                                    if (isset($timeTable[$day][$i]['A']))
                                    { 
                                        $courseID = $timeTable[$day][$i]['A']['courseID'];
                                        echo "class='course course-$courseID' title='" . 
                                            htmlspecialchars($timeTable[$day][$i]['A']['courseName'], ENT_QUOTES, 'UTF-8') . "'>";
                                        echo htmlspecialchars(mb_substr($timeTable[$day][$i]['A']['courseName'], 0, 3, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                                        if (mb_strlen($timeTable[$day][$i]['A']['courseName'], 'UTF-8') > 3)
                                        {
                                            echo "...";
                                        }
                                    }
                                    else
                                    {
                                        echo ">";
                                    }
                                    echo "</td>";
                                }
                                echo "</tr>";

                                // B row
                                echo "<tr>";
                                foreach (array('월', '화', '수', '목', '금') as $day)
                                {
                                    $dayEng = $daysEnglish[$day];
                                    echo "<td ";
                                    if (isset($timeTable[$day][$i]['B']))
                                    { 
                                        $courseID = $timeTable[$day][$i]['B']['courseID'];
                                        echo "class='course course-$courseID' title='" . 
                                            htmlspecialchars($timeTable[$day][$i]['B']['courseName'], ENT_QUOTES, 'UTF-8') . "'>";
                                        echo htmlspecialchars(mb_substr($timeTable[$day][$i]['B']['courseName'], 0, 3, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                                        if (mb_strlen($timeTable[$day][$i]['B']['courseName'], 'UTF-8') > 3)
                                        {
                                            echo "...";
                                        }
                                    }
                                    else
                                    {
                                        echo ">";
                                    }
                                    echo "</td>";
                                }
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 수강신청 검색 -->
            <form method="get" action="<?= $_SERVER['PHP_SELF'] ?>">
                <input type="hidden" name="perform_search" value="1"> <!-- 검색 실행 플래그 -->
                <div class="searchSection">
                    <div class="searchRow">
                        <label for="searchType">검색구분</label>
                        <select id="searchType" name="searchType">
                            <option value="all" <?= (isset($_GET['searchType']) && $_GET['searchType'] == 'all') || !isset($_GET['searchType']) ? 'selected' : '' ?>>전체</option>
                            <option value="cart" <?= isset($_GET['searchType']) && $_GET['searchType'] == 'cart' ? 'selected' : '' ?>>장바구니</option>
                            <option value="area" <?= isset($_GET['searchType']) && $_GET['searchType'] == 'area' ? 'selected' : '' ?>>영역별</option>
                            <option value="college_department" <?= isset($_GET['searchType']) && $_GET['searchType'] == 'college_department' ? 'selected' : '' ?>>단과대학/학과</option>
                        </select>

                        <label for="keyword">검색어</label>
                        <input type="text" id="keyword" name="keyword" placeholder="과목명 또는 교수명"
                            value="<?= isset($_GET['keyword']) ? htmlspecialchars($_GET['keyword']) : '' ?>">
                    </div>

                    <div class="searchRow">
                        <label for="detailSearch">상세검색</label>
                        <select id="detailSearch" name="detailSearch" disabled>
                            <option value="">선택</option>
                            <?php
                            // PHP에서 searchType에 따라 초기 옵션 로드 (페이지 리로드 시 선택값 유지를 위해)
                            if (isset($_GET['searchType']) && isset($_GET['detailSearch']) && $_GET['detailSearch'] !== '')
                            {
                                $currentSearchType = $_GET['searchType'];
                                $currentDetailSearch = $_GET['detailSearch'];
                                if ($currentSearchType == 'area')
                                {
                                    foreach ($areas as $area_item)
                                    {
                                        echo "<option value=\"".htmlspecialchars($area_item)."\" ".($currentDetailSearch == $area_item ? 'selected' : '').">".htmlspecialchars($area_item)."</option>";
                                    }
                                }
                                elseif ($currentSearchType == 'college_department')
                                {
                                    if ($colleges && $colleges->num_rows > 0)
                                    {
                                        $colleges->data_seek(0); // 포인터 초기화
                                        while ($college = $colleges->fetch_assoc())
                                        {
                                            echo "<option value=\"".$college['collegeID']."\" ".($currentDetailSearch == $college['collegeID'] ? 'selected' : '').">".htmlspecialchars($college['collegeName'])."</option>";
                                        }
                                    }
                                }
                            }
                            ?>
                        </select>

                        <label for="department"></label>
                        <select id="department" name="department" disabled>
                            <option value="">선택</option>
                            <?php
                            if (isset($_GET['searchType']) && $_GET['searchType'] == 'college_department' && isset($_GET['department']) && $_GET['department'] !== '' && isset($_GET['detailSearch']) && $_GET['detailSearch'] !== '')
                            {
                                $currentDepartment = $_GET['department'];
                                $currentCollegeForDept = $_GET['detailSearch'];
                                if ($departments && $departments->num_rows > 0)
                                {
                                    $departments->data_seek(0);
                                    while ($dept = $departments->fetch_assoc())
                                    {
                                        if ($dept['collegeID'] == $currentCollegeForDept)
                                        {
                                            echo "<option value=\"".$dept['departmentID']."\" data-college=\"".$dept['collegeID']."\" ".($currentDepartment == $dept['departmentID'] ? 'selected' : '').">".htmlspecialchars($dept['departmentName'])."</option>";
                                        }
                                    }
                                }
                            }
                            ?>
                        </select>
                    </div> 
                    
                    <div class="buttonRow">
                        <button type="submit" class="button searchButton">조회</button>
                        <button type="button" class="button resetButton" onclick="resetSearch()">초기화</button>
                    </div>
                </div>
            </form>                

            <div class="notification">
                <div style="display: flex; align-items: center;">
                    <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNiIgaGVpZ2h0PSIxNiIgdmlld0JveD0iMCAwIDE2IDE2IiBmaWxsPSIjOTk5Ij48cGF0aCBkPSJNOCAxQzQuMTQgMSAxIDQuMTQgMSA4QzEgMTEuODYgNC4xNCAxNSA4IDE1QzExLjg2IDE1IDE1IDExLjg2IDE1IDhDMTUgNC4xNCAxMS44NiAxIDggMU0gOCAxNkM0LjY4NiAxNiAyIDE0LjMxNCAyIDEwQzIgNS42ODYgNC42ODYgMiA4IDJDMTEuMzEzIDIgMTQgNS42ODYgMTQgMTBDMTQgMTQuMzE0IDExLjMxMyAxNiA4IDE2Ij48L3BhdGg+PHBhdGggZD0iTTcgM0g5VjlIN1YzWk0gNyAxMUg5VjEzSDdWMTFaIj48L3BhdGg+PC9zdmc+" alt="정보">
                    실시간으로 수강신청 상태가 반영됩니다. 모든 신청은 시스템에 즉시 기록됩니다.
                </div>
                <div class="quickEnrollContainer">
                    <form method="post" action="enroll.php" id="quickEnrollForm" style="display: flex; align-items: center; gap: 10px;">
                        <input type="text" id="quickEnrollCourseID" name="quickEnrollCourseID" placeholder="과목코드 입력" required>
                        <input type="hidden" name="action" value="quickEnroll">
                        <button type="submit" onclick="return quickEnrollSubmit();">신청</button>
                    </form>
                </div>
            </div>

            <!-- 강의 목록 테이블 -->
            <table>
                <caption>강의 목록</caption>
                <thead>
                <tr>
                    <th style="width: 40px;">No.</th>
                    <th style="width: 80px;">이수구분</th>
                    <th style="width: 90px;">과목코드</th>
                    <th style="width: 120px;">교과목명</th>
                    <th style="width: 110px;">학과</th>
                    <th style="width: 110px;">교수명</th>
                    <th style="width: 70px;">학점</th>
                    <th style="width: 140px;">강의시간</th>
                    <th style="width: 110px;">정원/신청</th>
                    <th style="width: 110px;">신청</th>
                </tr>
                </thead>
                <tbody>
                    <?php
                    if ($searchResults !== null && $searchResults->num_rows > 0)
                    {
                        $rowNum = 1;
                        while ($course = $searchResults->fetch_assoc())
                        {
                            // 이미 수강신청한 강의인지 확인
                            $alreadyEnrolled = false;
                            if ($enrolledCourses && $enrolledCourses->num_rows > 0)
                            {
                                $enrolledCourses->data_seek(0);
                                while ($enrolledCourseItem = $enrolledCourses->fetch_assoc())
                                {
                                    if ($enrolledCourseItem['courseID'] == $course['courseID'])
                                    {
                                        $alreadyEnrolled = true;
                                        break;
                                    }
                                }
                            }
                    ?>
                    <tr>
                        <td><?= $rowNum++ ?></td>
                        <td><?= htmlspecialchars($course['creditType']) ?></td>
                        <td><?= htmlspecialchars($course['courseID']) ?></td>
                        <td><?= htmlspecialchars($course['courseName']) ?></td>
                        <td><?= htmlspecialchars($course['departmentName']) ?></td>
                        <td><?= htmlspecialchars($course['professor']) ?></td>
                        <td><?= htmlspecialchars($course['credits']) ?></td>
                        <td><?= htmlspecialchars($course['courseTimesFormatted']) ?></td>
                        <td><?= htmlspecialchars($course['capacity']) ?>/<?= htmlspecialchars($course['currentEnrollment']) ?></td>
                        <td>
                            <?php
                            if ($alreadyEnrolled)
                            {
                            ?>
                                <form method="post" action="enroll.php" style="display:inline;" onsubmit="return confirm('정말로 이 강의를 취소하시겠습니까?');">
                                    <input type="hidden" name="courseID" value="<?= $course['courseID'] ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <button type="submit" class="deleteButton">취소</button>
                                </form>
                            <?php
                            }
                            else
                            {
                            ?>
                                <button class="registerButton" onclick="enrollCourse('<?= htmlspecialchars($course['courseID']) ?>')">신청</button>
                            <?php
                            }
                            ?>
                        </td>
                    </tr>
                    <?php
                        }
                        if ($stmt_search)
                        {
                            $stmt_search->close(); // 검색 결과 사용 후 닫기
                        }
                    }
                    elseif (isset($_GET['perform_search']) && $_GET['perform_search'] == '1')
                    {
                    ?>
                    <tr>
                        <td colspan="10">검색 결과가 없습니다.</td>
                    </tr>
                    <?php
                    }
                    else
                    {
                    ?>
                    <tr>
                        <td colspan="10">위에서 조회 버튼을 클릭하여 강의를 검색하세요.</td>
                    </tr>
                    <?php
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

<script>
    // PHP에서 가져온 데이터
    const phpAreas = <?= json_encode($areas) ?>;
    const phpColleges = <?= json_encode($colleges_arr_for_js) ?>;
    const phpDepartments = <?= json_encode($departments_arr_for_js) ?>;

    const searchTypeSelect = document.getElementById('searchType');
    const detailSearchSelect = document.getElementById('detailSearch');
    const departmentSelect = document.getElementById('department');
    const keywordInput = document.getElementById('keyword');

    function populateDetailSearch()
    {
        const selectedType = searchTypeSelect.value;
        detailSearchSelect.innerHTML = '<option value="">선택</option>'; // 초기화
        departmentSelect.innerHTML = '<option value="">선택</option>'; // 학과도 초기화
        departmentSelect.disabled = true;

        if (selectedType === 'area')
        {
            detailSearchSelect.disabled = false;
            phpAreas.forEach(area =>
            {
                const option = new Option(area, area);
                detailSearchSelect.add(option);
            });
        }
        else if (selectedType === 'college_department')
        {
            detailSearchSelect.disabled = false;
            phpColleges.forEach(college =>
            {
                const option = new Option(college.collegeName, college.collegeID);
                detailSearchSelect.add(option);
            });
        }
        else
        { // 'all', 'cart'
            detailSearchSelect.disabled = true;
        }
    }

    function populateDepartments()
    {
        const selectedCollegeID = detailSearchSelect.value;
        departmentSelect.innerHTML = '<option value="">선택</option>'; // 초기화

        if (searchTypeSelect.value === 'college_department' && selectedCollegeID)
        {
            departmentSelect.disabled = false;
            phpDepartments.forEach(dept =>
            {
                if (dept.collegeID == selectedCollegeID)
                { // 문자열 <-> 숫자 비교 주의
                    const option = new Option(dept.departmentName, dept.departmentID);
                    departmentSelect.add(option);
                }
            });
        }
        else
        {
            departmentSelect.disabled = true;
        }
    }

    searchTypeSelect.addEventListener('change', function()
    {
        populateDetailSearch();
        // 상세검색이 변경되면 학과도 자동으로 업데이트 시도 (또는 초기화)
        populateDepartments();
    });

    detailSearchSelect.addEventListener('change', function()
    {
        if (searchTypeSelect.value === 'college_department')
        {
            populateDepartments();
        }
    });

    // 페이지 로드 시 초기 상태 설정
    document.addEventListener('DOMContentLoaded', function()
    {
        populateDetailSearch(); // 현재 searchType에 맞게 detailSearch 옵션 채우기
        // 만약 GET 파라미터로 detailSearch 값이 넘어왔다면 해당 값으로 설정
        const currentDetailSearchVal = '<?= isset($_GET['detailSearch']) ? htmlspecialchars($_GET['detailSearch']) : '' ?>';
        if (currentDetailSearchVal && !detailSearchSelect.disabled)
        {
            detailSearchSelect.value = currentDetailSearchVal;
        }

        populateDepartments(); // detailSearch 값에 따라 department 옵션 채우기
        // 만약 GET 파라미터로 department 값이 넘어왔다면 해당 값으로 설정
        const currentDepartmentVal = '<?= isset($_GET['department']) ? htmlspecialchars($_GET['department']) : '' ?>';
        if (currentDepartmentVal && !departmentSelect.disabled)
        {
            departmentSelect.value = currentDepartmentVal;
        }
    });

    function resetSearch()
    {
        searchTypeSelect.value = 'all';
        keywordInput.value = '';
        populateDetailSearch(); // detailSearch 및 department 초기화
        // GET 파라미터 없이 페이지 리로드하여 완전히 초기화
        window.location.href = window.location.pathname;
    }

    // 과목코드로 바로 신청 및 신청 버튼 공통 함수
    function submitEnroll(courseID, actionType)
    {
        const quickEnrollForm = document.getElementById('quickEnrollForm');
        const courseIDInput = document.getElementById('quickEnrollCourseID');
        const actionInput = quickEnrollForm.querySelector('input[name="action"]');

        if (!courseID)
        {
            alert('과목코드를 입력해주세요.');
            return false;
        }

        // 폼의 값을 설정
        courseIDInput.value = courseID;
        actionInput.value = actionType;
        quickEnrollForm.submit();
        return true;
    }

    // 과목코드 입력으로 신청
    function quickEnrollSubmit()
    {
        const courseIDInput = document.getElementById('quickEnrollCourseID').value.trim();
        return submitEnroll(courseIDInput, 'quickEnroll');
    }

    // 신청 버튼으로 수강신청
    function enrollCourse(courseID)
    {
        submitEnroll(courseID, 'enroll');
    }
</script>
</body>
</html>
<?php
$conn->close();
?>