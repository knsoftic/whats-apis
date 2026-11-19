<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  string|null  $title  Browser tab / header title
     * @param  bool  $full  Full-height page without padding (chat screen)
     */
    public function __construct(
        public ?string $title = null,
        public bool $full = false,
    ) {}

    public function render(): View
    {
        return view('layouts.app');
    }
}
