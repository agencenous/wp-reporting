<?php

namespace WPReporting;

if(!class_exists('WPReporting\Reporting')) {
    class WP_Reporting{
        var $projects;
        var $settings;
        var $categories;
        var $current_project;

        public function __construct() {
            $this->projects = [];

            $this->categories = [
                'main' => 'Main',
                'plugin' => 'Plugins',
                'theme' => 'Themes',
            ];
            
            require_once __DIR__.'/settings.php';
            $this->settings = new Settings();
        }

        /**
         * Register a project
         * @param string $project_name
         * @param array $params
         * @return WP_Reporting
         */
        public function register(string $project_name, array $params) : WP_Reporting{
            $params = wp_parse_args( $params, [
                'to' => null,
                'name' => $project_name,
                'label' => $project_name,
                'description' => null,
                'prefix' => $project_name,
                'only_in_dir' => null,
                'default_enabled' => false,
                'category' => 'main',
                'trace_in_logs' => false,
            ] );

            if(!isset($this->categories[$params['category']])){
                $params['category'] = 'main';
            }

            // Allows to pass base64_encode email addresses, in order to prevent from spaming by exposing them in the code
            if($params['to'] && !strstr($params['to'], '@')){
                $params['to'] = base64_decode($params['to']);
            }
            // Ensure email address is correct
            if(filter_var($params['to'], FILTER_VALIDATE_EMAIL) === false){
                $params['to'] = get_option('admin_email');
            }

            $params['enabled'] = $this->settings->Get($project_name);

            $this->projects[$project_name] = apply_filters('wp-reporting:project:register', $params, $project_name);
            return $this;
        }

        /**
         * Get all categories
         * @return array
         */
        public function get_categories() : array {
            return $this->categories;
        }

        /**
         * Get all projects
         * @return array
         */
        public function get_projects() : array {
            return $this->projects;
        }

        /**
         * Get a project
         * @param string $project_name
         * @return array|null
         */
        public function get_project(string $project_name){
            return (isset($this->projects[$project_name]) ? $this->projects[$project_name] : null);
        }

        /**
         * Get current project
         */
        public function get_current_project(){
            return $this->current_project;
        }

        /**
         * Set current project
         * @var string $project_name
         */
        public function set_current_project(string $project_name){
            $this->current_project = $project_name;
            return $this;
        }

        /**
         * Send a report
         * @param Exception $exception
         * @param string $project_name
         * @return bool
         */
        public function send($exception, string $project_name) : bool {

            // Get project
            $project = $this->get_project($project_name);
            if(null === $project){
                error_log(sprintf('[WP-Report]: Try to send report on unfound project: "%s"', $project_name));
                return false;
            }

            if(isset($project['only_in_dir'])){
                $error_file = $exception->getFile();
                // Check if if file is in directory
                if(!strstr($error_file, $project['only_in_dir'])){
                    return false;
                }
            }
            
            $enabled = $this->settings->get($project_name);
            
            
            // Get recipient
            $to = $project['to'];
            $to = apply_filters('wp-reporting:send:to', $to);
            
            // Get subject
            $prefix = $project['prefix'];
            $subject_prefix = apply_filters('wp-reporting:send:subject_prefix', sprintf('[%s]', $prefix), $exception, $project);
            $subject = apply_filters('wp-reporting:send:subject', $subject_prefix.' '.$exception->getMessage(), $exception, $project);
            
            // Get message
            $stack = apply_filters('wp-reporting:send:stack', $exception->getTrace());
            $trace = debug_backtrace();
            // Cleanup first items if it was listened
            if(isset($trace[0]['class']) && $trace[0]['class'] === 'WPReporting\\WP_Reporting'){
                array_shift($trace);
            }
            if(isset($trace[0]['class']) && $trace[0]['class'] === 'WPReporting\\WP_Reporting'){
                array_shift($trace);
            }
            // Reduce trace, because too much data causes error
            $trace = array_slice($trace, 0, 10);
            $json = json_encode(['stack'=>$stack, 'trace'=>$trace], JSON_PRETTY_PRINT);
            $message = \apply_filters('wp-reporting:send:message', '<h1>'.sprintf('Error in %s', get_option('blogname')).'</h1>'."\n".'<p>'.sprintf('<code>%s</code> in <em>%s</em> at line <strong>%s</strong>.', $exception->getMessage(), $exception->getFile(), $exception->getLine()).'</p>');
            $body = $message."\n\n<pre>```\n".$json."\n```</pre>";
            $body = \apply_filters('wp-reporting:send:body', $body);
            
            if(defined('WP_DEBUG') && WP_DEBUG){
                if(defined('WP_DEBUG_LOG') && WP_DEBUG_LOG){
                    $error_location = sprintf('%s:%s', $exception->getFile(), $exception->getLine());
                    error_log("[WP-Report]: {$subject}\t{$error_location}".($project['trace_in_logs'] ? "\t".json_encode($stack) : ''));
                }
            }
            if(!$enabled){
                return false;
            }

            // Send report by mail
            if(function_exists('wp_mail')){
                $mail = \wp_mail($to, $subject, $body, 'Content-Type: text/html; charset=UTF-8');
            }
            else{
                $mail = mail($to, $subject, $body, 'Content-Type: text/html; charset=UTF-8');
            }

            return $mail;
        }

        /**
         * Start error listening
         * Set error handler to catch errors
         * @param $level E_WARNING
         */
        public function listen(string $project, $level = E_WARNING){
            $this->set_current_project($project);
            set_error_handler(function($errno, $errstr, $errfile, $errline) {
                // error was suppressed with the @-operator
                if (0 === \error_reporting()) {
                    return false;
                }

                $this->send(new \ErrorException($errstr, 0, $errno, $errfile, $errline), WPReporting()->get_current_project());
                return false;
            }, $level);
        }

        /**
         * Stop error listening
         */
        public function stop(){
            restore_error_handler();
        }

        /**
         * Get the plugin version
         */
        public function get_version(){
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'));
            return $composer->version;
        }
    }
}