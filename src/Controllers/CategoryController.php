<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CategoryController
{
    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $sortOrder = $this->parseSortOrder($data['sort_order'] ?? null);

        if ($name === '') {
            return $this->redirectError($response, 'Kategoriename ist ein Pflichtfeld.');
        }

        if ($sortOrder === null) {
            return $this->redirectError($response, 'Sortierung muss eine ganze Zahl zwischen 0 und 9999 sein.');
        }

        if (Category::where('name', $name)->exists()) {
            return $this->redirectError($response, 'Eine Kategorie mit diesem Namen existiert bereits.');
        }

        try {
            Category::create([
                'name' => $name,
                'sort_order' => $sortOrder,
            ]);
        } catch (QueryException $exception) {
            return $this->redirectError($response, 'Eine Kategorie mit diesem Namen existiert bereits.');
        }

        return $this->redirectSuccess($response, 'Kategorie erfolgreich angelegt.');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->redirectError($response, 'Kategorie nicht gefunden.');
        }

        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $sortOrder = $this->parseSortOrder($data['sort_order'] ?? null);

        if ($name === '') {
            return $this->redirectError($response, 'Kategoriename ist ein Pflichtfeld.');
        }

        if ($sortOrder === null) {
            return $this->redirectError($response, 'Sortierung muss eine ganze Zahl zwischen 0 und 9999 sein.');
        }

        $category = Category::find($id);

        if (!$category) {
            return $this->redirectError($response, 'Kategorie nicht gefunden.');
        }

        if (Category::where('name', $name)->where('id', '!=', $id)->exists()) {
            return $this->redirectError($response, 'Eine Kategorie mit diesem Namen existiert bereits.');
        }

        try {
            $category->update([
                'name' => $name,
                'sort_order' => $sortOrder,
            ]);
        } catch (QueryException $exception) {
            return $this->redirectError($response, 'Eine Kategorie mit diesem Namen existiert bereits.');
        }

        return $this->redirectSuccess($response, 'Kategorie erfolgreich aktualisiert.');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->redirectError($response, 'Kategorie nicht gefunden.');
        }

        $category = Category::find($id);

        if (!$category) {
            return $this->redirectError($response, 'Kategorie nicht gefunden.');
        }

        $category->songs()->detach();
        $category->delete();

        return $this->redirectSuccess($response, 'Kategorie erfolgreich geloescht.');
    }

    private function parseSortOrder(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }

        $stringValue = trim((string) $value);
        if (!ctype_digit($stringValue)) {
            return null;
        }

        $sortOrder = (int) $stringValue;
        if ($sortOrder > 9999) {
            return null;
        }

        return $sortOrder;
    }

    private function redirectError(Response $response, string $message): Response
    {
        $_SESSION['error'] = $message;
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }

    private function redirectSuccess(Response $response, string $message): Response
    {
        $_SESSION['success'] = $message;
        return $response->withHeader('Location', '/song-library')->withStatus(302);
    }
}
