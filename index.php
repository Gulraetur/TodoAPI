<?php
error_reporting(E_ALL); ini_set('display_errors', 1);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Коррекция пути для подпапки /TodoAPI
$base = '/TodoAPI';
$requestUri = $_SERVER['REQUEST_URI'];
if (strpos($requestUri, $base) === 0) {
    $path = substr($requestUri, strlen($base));
} else {
    $path = $requestUri;
}
$path = strtok($path, '?'); // удаляем GET-параметры

$method = $_SERVER['REQUEST_METHOD'];

// Маршрутизация
if ($path === '/tasks' || $path === '/tasks/') {
    handleTasksCollection($method);
} elseif (preg_match('#^/tasks/(\d+)$#', $path, $matches)) {
    $id = (int)$matches[1];
    handleTaskResource($method, $id);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}

function handleTasksCollection($method) {
    global $conn;

    switch ($method) {
        case 'GET':
            try {
                $result = $conn->query('SELECT * FROM tasks ORDER BY created_at DESC');
                $tasks = $result->fetch_all(MYSQLI_ASSOC);
                echo json_encode($tasks);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['title'], $input['description'], $input['status'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields: title, description, status']);
                return;
            }

            $title = trim($input['title']);
            $description = trim($input['description']);
            $status = trim($input['status']);

            if ($title === '' || $description === '' || $status === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Fields cannot be empty']);
                return;
            }

            try {
                $stmt = $conn->prepare('INSERT INTO tasks (title, description, status, created_at) VALUES (?, ?, ?, NOW())');
                $stmt->bind_param('sss', $title, $description, $status);
                $stmt->execute();

                $newId = $stmt->insert_id;
                $stmt->close();

                $result = $conn->query("SELECT * FROM tasks WHERE id = $newId");
                $newTask = $result->fetch_assoc();

                http_response_code(201);
                echo json_encode($newTask);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleTaskResource($method, $id) {
    global $conn;

    // Проверка существования задачи (для GET, PUT, DELETE)
    try {
        $stmt = $conn->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $task = $result->fetch_assoc();
        $stmt->close();

        if (!$task && $method !== 'PUT' && $method !== 'DELETE') {
            http_response_code(404);
            echo json_encode(['error' => 'Task not found']);
            return;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        return;
    }

    switch ($method) {
        case 'GET':
            echo json_encode($task);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON']);
                return;
            }

            $updates = [];
            $params = [];
            $types = '';

            if (isset($input['title'])) {
                $title = trim($input['title']);
                if ($title === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Title cannot be empty']);
                    return;
                }
                $updates[] = 'title = ?';
                $params[] = $title;
                $types .= 's';
            }

            if (isset($input['description'])) {
                $description = trim($input['description']);
                if ($description === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Description cannot be empty']);
                    return;
                }
                $updates[] = 'description = ?';
                $params[] = $description;
                $types .= 's';
            }

            if (isset($input['status'])) {
                $status = trim($input['status']);
                if ($status === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Status cannot be empty']);
                    return;
                }
                $updates[] = 'status = ?';
                $params[] = $status;
                $types .= 's';
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }

            try {
                $params[] = $id;
                $types .= 'i';

                $sql = 'UPDATE tasks SET ' . implode(', ', $updates) . ' WHERE id = ?';
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();

                // Проверка, была ли задача удалена во время запроса
                if ($stmt->affected_rows === 0) {
                    $check = $conn->query("SELECT id FROM tasks WHERE id = $id");
                    if ($check->num_rows === 0) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Task not found']);
                        return;
                    }
                }

                $result = $conn->query("SELECT * FROM tasks WHERE id = $id");
                $updatedTask = $result->fetch_assoc();
                echo json_encode($updatedTask);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            }
            break;

        case 'DELETE':
            try {
                $stmt = $conn->prepare('DELETE FROM tasks WHERE id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();

                if ($stmt->affected_rows === 0) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Task not found']);
                    return;
                }

                http_response_code(204); // No content
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}