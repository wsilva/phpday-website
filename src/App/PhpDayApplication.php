<?php

namespace App;

use App\Controller\ApiControllerProvider;
use App\Controller\CFPControllerProvider;
use App\Controller\MainControllerProvider;
use App\Event\Subscriber\NotifyReceivedCFPSubscriber;
use App\Notification\Mailer;
use App\Notification\Slack;
use Saxulum\DoctrineMongoDb\Silex\Provider\DoctrineMongoDbProvider;
use Silex\Application;
use Silex\Provider\FormServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\SwiftmailerServiceProvider;
use Silex\Provider\TranslationServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Silex\Provider\ValidatorServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Main application
 */
class PhpDayApplication extends Application
{
    /** @var array */
    private $config;

    /**
     * Constructor
     *
     * @param string $rootDir
     * @param string $env
     * @param string $debug
     */
    public function __construct($rootDir, $env, $debug)
    {
        parent::__construct();

        $this['debug'] = $debug;

        $configFile = sprintf('%s/config/%s.php', $rootDir, $env);
        if (!is_readable($configFile)) {
            throw new \LogicException('Unable to load Environment configuration.');
        }

        $this->registerAppProviders();
        $this->configureServices();

        $this->config = call_user_func(require $configFile, $this);

        $this->mount('/{_locale}', new MainControllerProvider());
        $this->mount('/api', new ApiControllerProvider());
        $this->configureDefaultRoute();
        $this->configureCallForPapers();

        $this['controllers']->value('_locale', 'es');
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
        $this->register(new TwigServiceProvider());
        $this->register(new SessionServiceProvider());
        $this->register(new TranslationServiceProvider());
        $this->register(new FormServiceProvider());
        $this->register(new ValidatorServiceProvider());
        $this->register(new UrlGeneratorServiceProvider());
        $this->register(new DoctrineMongoDbProvider());
        $this->register(new SwiftmailerServiceProvider());
    }

    private function configureServices()
    {
        $this['section_bag'] = $this->share(function () {
            $bag = new SectionBag();

            $bag->registerSection('speakers', true);
            $bag->registerSection('registration', true);
            $bag->registerSection('cfp', false);
            $bag->registerSection('schedule', true);
            $bag->registerSection('sponsors', true);
            $bag->registerSection('ticket_sale', false);
            $bag->registerSection('testimonials', false);
            $bag->registerSection('mailing_list', true);

            return $bag;
        });

        $this['translator'] = $this->share($this->extend('translator', function (Translator $translator) {
            $translator->addLoader('yaml', new YamlFileLoader());

            $resourceDir = $this->getResourceDir('translations');

            $translator->addResource('yaml', $resourceDir.'/es.yml', 'es');
            $translator->addResource('yaml', $resourceDir.'/en.yml', 'en');

            $translator->addResource('yaml', $resourceDir.'/talks.es.yml', 'es', 'talks');
            $translator->addResource('yaml', $resourceDir.'/talks.en.yml', 'en', 'talks');

            return $translator;
        }));

        $this['twig'] = $this->share($this->extend('twig', function (\Twig_Environment $twig) {
            $twig->addExtension(new \Twig_Extensions_Extension_Intl());

            $twig->addGlobal('section_bag', $this['section_bag']);
            $twig->addGlobal('speakers', $this['speakers']);
            $twig->addGlobal('schedule_info', $this['schedule_info']);
            $twig->addFilter(new \Twig_SimpleFilter('nonl', function ($text) {
                return str_replace(PHP_EOL, '', $text);
            }, ['pre_escape' => 'html', 'is_safe' => ['html']]));

            return $twig;
        }));

        $this['slack'] = $this->share(function () {
            $slack = new Slack($this['slack.options']['hook_url']);

            return $slack;
        });

        $this['appMailer'] = $this->share(function () {
            return new Mailer($this['mailer'], $this['translator']);
        });
    }

    private function configureCallForPapers()
    {
        $this->mount('/{_locale}/cfp', new CFPControllerProvider());

        $this['dispatcher']->addSubscriber(
            new NotifyReceivedCFPSubscriber($this['slack'], $this['appMailer'])
        );
    }

    private function configureDefaultRoute()
    {
        $this->get('/', function (Application $app, Request $request) {
            // Redirect to the homepage with the default locale param.
            $url = $app['url_generator']->generate('homepage', [
                '_locale' => $app['locale_fallback'],
            ]);

            $subRequest = Request::create($url, 'GET', [], $request->cookies->all(), [], $request->server->all());
            if ($request->hasSession()) {
                $subRequest->setSession($request->getSession());
            }

            $response = $app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);

            return $response;
        });
    }
}
