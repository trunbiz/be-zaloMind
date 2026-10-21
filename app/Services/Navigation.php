<?php

namespace App\Services;

use App\Models\User;

class Navigation
{
    public function items(string $area, User $user): array
    {
        if ($area === 'admin' && ! $user->hasRole('admin')) {
            return [];
        }

        $items = [];
        foreach (config('menu.'.$area, []) as $item) {
            if (isset($item['roles']) && ! $user->hasAnyRole($item['roles'])) {
                continue;
            }
            if (isset($item['permission']) && ! $user->can($item['permission'])) {
                continue;
            }
            if (isset($item['children'])) {
                $item['children'] = $this->prepareChildren($item['children'], $area, $user);
                if ($item['children'] === []) {
                    continue;
                }
                $item['active'] = collect($item['children'])->contains('active', true);
            } else {
                $item = $this->prepareItem($item, $area);
            }
            $items[] = $item;
        }

        return $items;
    }

    public function pages(string $area, User $user): array
    {
        $pages = [];
        foreach ($this->items($area, $user) as $item) {
            foreach ($item['children'] ?? [$item] as $page) {
                $page['group'] = isset($item['children']) ? $item['label'] : ($area === 'admin' ? 'Quản trị' : 'Không gian làm việc');
                $pages[] = $page;
            }
        }

        return $pages;
    }

    public function page(string $area, string $key, User $user): array
    {
        return collect($this->pages($area, $user))->firstWhere('key', $key) ?? abort(404);
    }

    private function prepareChildren(array $children, string $area, User $user): array
    {
        return collect($children)
            ->filter(fn (array $item) => (! isset($item['roles']) || $user->hasAnyRole($item['roles']))
                && (! isset($item['permission']) || $user->can($item['permission'])))
            ->map(fn (array $item) => $this->prepareItem($item, $area))
            ->values()->all();
    }

    private function prepareItem(array $item, string $area): array
    {
        $route = $item['route'] ?? ($area === 'admin' ? 'admin.section' : 'workspace.section');
        $parameters = isset($item['route']) ? [] : ['section' => $item['key']];
        $item['url'] = route($route, $parameters);
        $item['active'] = request()->routeIs($route)
            && (! isset($parameters['section']) || request()->route('section') === $item['key']);

        return $item;
    }
}
