<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;
use Throwable;

final class HomeController
{
    /** @var callable|null */
    private $marketingContentFactory;

    public function __construct(private View $view, ?callable $marketingContentFactory = null)
    {
        $this->marketingContentFactory = $marketingContentFactory;
    }

    public function index(): Response
    {
        $publicPlans = [];
        $publicTestimonials = [];

        if (is_callable($this->marketingContentFactory)) {
            try {
                $content = ($this->marketingContentFactory)();
            } catch (Throwable) {
                $content = null;
            }
            if (is_object($content)) {
                try {
                    $plans = $content->publicPlans();
                    $publicPlans = is_array($plans) ? $plans : [];
                } catch (Throwable) {
                    $publicPlans = [];
                }
                try {
                    $testimonials = $content->publicTestimonials();
                    $publicTestimonials = is_array($testimonials) ? $testimonials : [];
                } catch (Throwable) {
                    $publicTestimonials = [];
                }
            }
        }

        return $this->view->render('home', compact('publicPlans', 'publicTestimonials'));
    }
}
