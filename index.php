<?php
header('Content-Type: application/json');

// Подключение к БД
require_once 'config/config.php';

// Получаем метод и путь
$method = $_SERVER['REQUEST_METHOD'];
$path = str_replace('/TodoAPI', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$path = rtrim($path, '/'); // убираем trailing slash

if ($path === '/tasks') {
    // Работа со списком задач
    switch ($method) {
        case 'GET':
            $result = $conn->query("SELECT * FROM tasks ORDER BY created_at DESC");
            $tasks = [];
            while ($row = $result->fetch_assoc()) {
                $tasks[] = $row;
            }
            echo json_encode($tasks);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $title = trim($input['title'] ?? '');
            $description = trim($input['description'] ?? '');
            $status = trim($input['status'] ?? '');

            if (!$title || !$description || !$status) {
                http_response_code(400);
                echo json_encode(['error' => 'Все поля обязательны']);
                break;
            }

            $stmt = $conn->prepare("INSERT INTO tasks (title, description, status, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param('sss', $title, $description, $status);
            $stmt->execute();

            $newId = $stmt->insert_id;
            $stmt->close();

            $result = $conn->query("SELECT * FROM tasks WHERE id = $newId");
            $newTask = $result->fetch_assoc();

            http_response_code(201);
            echo json_encode($newTask);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Метод не поддерживается']);
    }
} elseif (preg_match('#^/tasks/(\d+)$#', $path, $matches)) {
    // Работа с одной задачей
    $id = (int)$matches[1];

    switch ($method) {
        case 'GET':
            $result = $conn->query("SELECT * FROM tasks WHERE id = $id");
            $task = $result->fetch_assoc();
            if ($task) {
                echo json_encode($task);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Задача не найдена']);
            }
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            $title = isset($input['title']) ? trim($input['title']) : null;
            $description = isset($input['description']) ? trim($input['description']) : null;
            $status = isset($input['status']) ? trim($input['status']) : null;

            // Проверяем, что хотя бы одно поле передано
            if ($title === null && $description === null && $status === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Нет данных для обновления']);
                break;
            }

            // Формируем запрос динамически
            $updates = [];
            $params = [];
            $types = '';

            if ($title !== null) {
                if ($title === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Title не может быть пустым']);
                    break;
                }
                $updates[] = "title = ?";
                $params[] = $title;
                $types .= 's';
            }
            if ($description !== null) {
                if ($description === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Description не может быть пустым']);
                    break;
                }
                $updates[] = "description = ?";
                $params[] = $description;
                $types .= 's';
            }
            if ($status !== null) {
                if ($status === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Status не может быть пустым']);
                    break;
                }
                $updates[] = "status = ?";
                $params[] = $status;
                $types .= 's';
            }

            $params[] = $id;
            $types .= 'i';

            $sql = "UPDATE tasks SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            if ($stmt->affected_rows > 0 || $stmt->errno === 0) {
                // Возвращаем обновлённую задачу
                $result = $conn->query("SELECT * FROM tasks WHERE id = $id");
                $updatedTask = $result->fetch_assoc();
                echo json_encode($updatedTask);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Задача не найдена']);
            }
            $stmt->close();
            break;

        case 'DELETE':
            $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                http_response_code(204); // нет содержимого
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Задача не найдена']);
            }
            $stmt->close();
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Метод не поддерживается']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Неверный путь']);
}