<?php

namespace App;

use Silex\Application;
use Silex\Provider\TranslationServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Main application
 */
class PhpDayApplication extends Application
{
    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        parent::boot();

        $this['debug'] = false;

        $this->registerAppProviders();
        $this->configureServices();

        $this->get('/', function () {
            return $this['twig']->render('index.html.twig');
        });

        $this->get('/{section}', function (Application $app, $section) {
            try {
                return $app['twig']->render(sprintf('%s.html.twig', $section));
            } catch (\Twig_Error_Loader $e) {
                throw new NotFoundHttpException('Page not found.', $e);
            }
        });
    }

    /**
     * Get the path to the given resource
     *
     * @param string $resource
     *
     * @return string
     */
    private function getResourceDir($resource)
    {
        return sprintf('%s/Resources/%s', __DIR__, $resource);
    }

    private function registerAppProviders()
    {
        $this->register(new TwigServiceProvider(), [
            'twig.path'    => $this->getResourceDir('views'),
            'twig.options' => [
                'strict_variables' => true,
            ]
        ]);

        $this->register(new TranslationServiceProvider(), ['locale_fallbacks' => ['es']]);
    }

    private function configureServices()
    {
        $this['section_bag'] = $this->share(function () {
            $bag = new SectionBag();

            $bag->registerSection('speakers', false);
            $bag->registerSection('schedule', false);
            $bag->registerSection('sponsors', false);
            $bag->registerSection('ticket_sale', false);
            $bag->registerSection('testimonials', false);
            $bag->registerSection('mailing_list', true);

            return $bag;
        });


        $this['translator'] = $this->share($this->extend('translator', function (Translator $translator, $app) {
            $translator->addLoader('yaml', new YamlFileLoader());

            $translator->addResource('yaml', $this->getResourceDir('translations').'/es.yml', 'es');

            return $translator;
        }));

        $this['twig']->addGlobal('section_bag', $this['section_bag']);
    }
}
