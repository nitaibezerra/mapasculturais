<?php
namespace OpportunityPhases;

use MapasCulturais\App,
    MapasCulturais\Entities,
    MapasCulturais\Definitions,
    MapasCulturais\Exceptions;


class Plugin extends \MapasCulturais\Plugin{
    
    /**
     * Retorna o projeto principal
     * 
     * @return \MapasCulturais\Entities\Opportunity
     */
    static function getBaseOpportunity(){
        $opportunity = self::getRequestedOpportunity();
        
        if(!$opportunity){
            return null;
        }
        
        if($opportunity->isOpportunityPhase){
            $opportunity = $opportunity->parent;
        }
        
        return $opportunity;
    }
    
    /**
     * Retorna o projeto/fase que está sendo visualizado
     * 
     * @return \MapasCulturais\Entities\Opportunity
     */
    static function getRequestedOpportunity(){
        $app = App::i();
        
        $opportunity = $app->view->controller->requestedEntity;
        
        if(!$opportunity){
            return null;
        }
        
        return $opportunity;
    }
    
    /**
     * Retorna a última fase do projeto
     * 
     * @param \MapasCulturais\Entities\Opportunity $base_opportunity
     * @return \MapasCulturais\Entities\Opportunity
     */
    static function getLastPhase(Entities\Opportunity $base_opportunity){
        $app = App::i();
        
        if ($base_opportunity->canUser('@control')) {
            $status = [0,-1];
        } else {
            $status = -1;
        }
        
        $result = $app->repo('Opportunity')->findOneBy([
            'parent' => $base_opportunity,
            'status' => $status
        ],['registrationTo' => 'DESC', 'id' => 'DESC']);
        
        return $result ? $result : $base_opportunity;
    }
    
    /**
     * Retorna a última fase que teve seu período de inscrição terminado
     * @param \MapasCulturais\Entities\Opportunity $base_opportunity
     * @return \MapasCulturais\Entities\Opportunity 
     */
    static function getLastCompletedPhase(Entities\Opportunity $base_opportunity){
        $now = new \DateTime;
        
        if($base_opportunity->registrationTo > $now){
            return null;
        }
        
        $result = $base_opportunity;
        $phases = self::getPhases($base_opportunity);
        
        foreach($phases as $phase){
            if($phase->registrationTo <= $now){
                $result = $phase;
            }
        }
        
        return $result;
    }
    
    /**
     * Retorna a fase atual
     * @param \MapasCulturais\Entities\Opportunity $base_opportunity
     * @return \MapasCulturais\Entities\Opportunity 
     */
    static function getCurrentPhase(Entities\Opportunity $base_opportunity){
        $now = new \DateTime;
        
        $result = $base_opportunity;
        $phases = self::getPhases($base_opportunity);
        
        foreach($phases as $phase){
            if($phase->registrationTo > $now){
                continue;
            }
            $result = $phase;
        }
        
        return $result;
    }
    
    /**
     * Retorna a fase anterior a fase informada
     * 
     * @param \MapasCulturais\Entities\Opportunity $phase
     * @return \MapasCulturais\Entities\Opportunity a fase anterior
     */
    static function getPreviousPhase(Entities\Opportunity $phase){
        if (!$phase->isOpportunityPhase) { 
            return null;
        }
        
        $base_opportunity = self::getBaseOpportunity();
        
        $phases = self::getPhases($base_opportunity);
        
        $result = $base_opportunity;
        
        foreach($phases as $p){
            if($p->registrationTo < $phase->registrationTo){
                $result = $p;
            }
        }
        
        return $result;
    }
    
    
    /**
     * Retorna as fases do projeto informado
     * 
     * @param \MapasCulturais\Entities\Opportunity $opportunity
     * @return \MapasCulturais\Entities\Opportunity[]
     */
    static function getPhases(Entities\Opportunity $opportunity){
        if ($opportunity->canUser('@control')) {
            $status = [0,-1];
        } else {
            $status = -1;
        }
        
        $app = App::i();
        $phases = $app->repo('Opportunity')->findBy([
            'parent' => $opportunity,
            'status' => $status
        ],['registrationTo' => 'ASC', 'id' => 'ASC']);
        
        $phases = array_filter($phases, function($item) { 
            if($item->isOpportunityPhase){
                return $item;
            }
        });
        
        return $phases;
    }
    
    /**
     * O projeto informado tem os requisitos mínimos para se criar novas fases?
     * 
     * @param \MapasCulturais\Entities\Opportunity $opportunity
     * @return type
     */
    static function canCreatePhases(Entities\Opportunity $opportunity){
        return $opportunity->useRegistrations && $opportunity->registrationTo;
    }
    
    function _init () {
        $app = App::i();
        
        $app->view->enqueueStyle('app', 'plugin-opportunity-phases', 'css/opportunity-phases.css');
        
        // action para criar uma nova fase no projeto
        $app->hook('GET(opportunity.createNextPhase)', function() use($app){
            $parent = $this->requestedEntity;
            
            $_phases = [
                \MapasCulturais\i::__('Segunda fase'),
                \MapasCulturais\i::__('Terceira fase'),
                \MapasCulturais\i::__('Quarta fase'),
                \MapasCulturais\i::__('Quinta fase'),
                \MapasCulturais\i::__('Sexta fase'),
                \MapasCulturais\i::__('Sétima fase'),
                \MapasCulturais\i::__('Oitava fase'),
                \MapasCulturais\i::__('Nona fase'),
                \MapasCulturais\i::__('Décima fase')
            ];
            
            $phases = self::getPhases($parent);
            
            $num_phases = count($phases);
            
            $phase = new Entities\Opportunity;
            $phase->status = Entities\Opportunity::STATUS_DRAFT;
            $phase->parent = $parent;
            $phase->name = $_phases[$num_phases];
            $phase->shortDescription = 'Descrição da ' . $_phases[$num_phases];
            $phase->type = $parent->type;
            $phase->owner = $parent->owner;
            $phase->useRegistrations = true;
            $phase->isOpportunityPhase = true;
            
            $last_phase = self::getLastPhase($parent);
            
            $_from = clone $last_phase->registrationTo;
            $_to = clone $last_phase->registrationTo;
            $_to->add(date_interval_create_from_date_string('1 days'));
            
            $phase->registrationFrom = $_from;
            $phase->registrationTo = $_to;
            

            $phase->save(true);

            $app->redirect($phase->editUrl);
        });
        
        // redireciona para a página do projeto após deletar uma fase
        $app->hook('DELETE(opportunity):beforeRedirect', function($entity, &$redirect_url){
            if($entity->isOpportunityPhase){
                $redirect_url = $entity->parent->singleUrl;
            }
        });
        
        // adiciona o botão de importar inscrições da fase anterior
        $app->hook('view.partial(singles/opportunity-registrations--tables--manager):before', function(){
            if($this->controller->action === 'create'){
                return;
            }
            
            $opportunity = $this->controller->requestedEntity;
        
            if($opportunity->isOpportunityPhase){
                $this->part('import-last-phase-button', ['entity' => $opportunity]);
            }
        });
        
        // adiciona na ficha de inscrição das fases o link para a inscrição anterior
        $app->hook('view.partial(singles/registration-<<edit|single>>--header):before', function() use($app){
            $registration = $this->controller->requestedEntity;
            if($prev_id = $registration->previousPhaseRegistrationId){
                $previous_phase_registration = $app->repo('Registration')->find($prev_id);
                $this->part('previous-phase-registration-link', ['previous_phase_registration' => $previous_phase_registration, 'registration' => $registration]);
            }
            
            if($next_id = $registration->nextPhaseRegistrationId){
                $next_phase_registration = $app->repo('Registration')->find($next_id);
                $this->part('next-phase-registration-link', ['next_phase_registration' => $next_phase_registration, 'registration' => $registration]);
            }
        });

        // action para importar as inscrições da última fase concluida
        $app->hook('GET(opportunity.importLastPhaseRegistrations)', function() use($app) {
            $target_opportunity = self::getRequestedOpportunity();
            
            $target_opportunity ->checkPermission('@control');
            
            if($target_opportunity->previousPhaseRegistrationsImported){
                $this->errorJson(\MapasCulturais\i::__('As inscrições já foram importadas para esta fase'), 400);
            }
            
            $previous_phase = self::getPreviousPhase($target_opportunity);
            
            $registrations = array_filter($previous_phase->getSentRegistrations(), function($item){
                if($item->status === Entities\Registration::STATUS_APPROVED){
                    return $item;
                }
            });
            
            if(count($registrations) < 1){
                $this->errorJson(\MapasCulturais\i::__('Não há inscrições aprovadas ou suplentes na fase anterior'), 400);
            }
            
            $new_registrations = [];
            
            $app->disableAccessControl();
            foreach ($registrations as $r){
                $reg = new Entities\Registration;
                $reg->owner = $r->owner;
                $reg->opportunity = $target_opportunity;
                $reg->status = Entities\Registration::STATUS_DRAFT;
                $reg->previousPhaseRegistrationId = $r->id;
                $reg->save(true);
                
                $r->nextPhaseRegistrationId = $reg->id;
                $r->save(true);
                
                $new_registrations[] = $reg;
            }
            
            $target_opportunity->previousPhaseRegistrationsImported = true;
            
            $target_opportunity->save(true);
            
            $app->enableAccessControl();
            
            $this->finish($new_registrations);
        });
        
        // desliga a edição do campo principal de data quando vendo uma fase
        $app->hook('view.partial(singles/opportunity-about--registration-dates).params', function(&$params){
            $opportunity = self::getRequestedOpportunity();
            $base_opportunity = self::getBaseOpportunity();
            
            if(!$opportunity) {
                return;
            }
            
            if($opportunity->isOpportunityPhase){
                $params['entity'] = $base_opportunity;
                $params['disable_editable'] = true;
            }
        });
        
        // subsitui a mensagem de projeto rascunho quando for uma fase de projeto
        $app->hook('view.partial(singles/entity-status).params', function(&$params, &$template_name){
            $opportunity = self::getRequestedOpportunity();
            
            if(!$opportunity){
                return;
            }
            
            if($opportunity->isOpportunityPhase){
                $template_name = 'opportunity-phase-status';
            }
        });
        
        // muda o status de publicação dos projetos
        $app->hook('view.partial(singles/control--edit-buttons).params', function(&$params) use ($app){
            $opportunity = self::getRequestedOpportunity();
            
            if(!$opportunity){
                return;
            }
            
            if($opportunity->isOpportunityPhase){
                $params['status_enabled'] = -1;
            }
        });
        
        // adiciona a lista e botão para criar novas fases
        $app->hook('view.partial(singles/widget-opportunities).params', function(&$params, &$template) use ($app){
            if($this->controller->action === 'create'){
                return;
            }
            
            $opportunity = self::getRequestedOpportunity();
            
            if($opportunity->isOpportunityPhase){
                $template = '_empty';
                return;
            }

            $params['opportunities'] = array_filter($params['opportunities'], function($e){
                if(! (bool) $e->isOpportunityPhase){
                    return $e;
                }
            });
            
            if($opportunity->isOpportunityPhase){
                $opportunity = $opportunity->parent;
            }

            if(!$opportunity->useRegistrations || !$opportunity->canUser('@controll')){
                return;
            }
            
        });
        
        // remove form de fazer inscrição das fases
        $app->hook('view.partial(singles/opportunity-registrations--form).params', function(&$data, &$template){
            $opportunity = self::getRequestedOpportunity();
            
            if(!$opportunity){
                return;
            }
            
            if($opportunity->isOpportunityPhase){
                echo '<br>';
                $template = '_empty';
            }
        });
        
        // remove opção de desativar inscrições online nas fases
        $app->hook('view.partial(singles/opportunity-about--online-registration-button).params', function(&$data, &$template){ 
            $opportunity = self::getRequestedOpportunity();
            
            if(!$opportunity){
                return;
            }
            
            if($opportunity->isOpportunityPhase){
                echo '<br>';
                $template = '_empty';
            }
        });
        
        // adiciona a lista de fases e o botão 'adicionar fase'
        $app->hook('template(opportunity.<<single|edit>>.tab-about--highlighted-message):end', function() use($app){
            $opportunity = self::getBaseOpportunity();
            
            if(!self::canCreatePhases($opportunity)){
                return;
            }
            
            $phases = self::getPhases($opportunity);
            
            $app->view->part('widget-opportunity-phases', ['opportunity' => $opportunity, 'phases' => $phases]);
        });


        // desabilita o modo de edição das partes abaixo
        $app->hook('view.partial(<<singles/type|entity-parent>>).params', function(&$data, &$template){
            $opportunity = $this->controller->requestedEntity;
            
            if(!$opportunity){
                return;
            }
            
            if($opportunity->isOpportunityPhase){
                $data['disable_editable'] = true;
            }
        });
        
        // remove a aba agenda de um projeto que é uma fase de outro projeto
        $app->hook('view.partial(<<agenda|singles/opportunity-events>>).params', function(&$data, &$template){
            $opportunity = $this->controller->requestedEntity;
            
            if(!$opportunity){
                return;
            }
            
            if($opportunity->isOpportunityPhase){
                $template = '_empty';
            }
        });
        
        // faz com que a fase seja acessível mes
        $app->hook('entity(Opportunity).canUser(view)', function($user, &$result){
            if($this->isOpportunityPhase && $this->status === -1){
                $result = true;
            }
        });
        
        $app->hook('POST(registration.index):before', function() use($app) {
            $opportunity = $app->repo('Opportunity')->find($this->data['opportunityId']);
            
            if($opportunity->isOpportunityPhase){
                throw new Exceptions\PermissionDenied($app->user, $opportunity, 'register');
            }
        });
    }
    
    
    function register () {
        $app = App::i();

        $def__is_opportunity_phase = new Definitions\Metadata('isOpportunityPhase', ['label' => \MapasCulturais\i::__('Is a opportunity phase?')]);
        $def__previous_phase_imported = new Definitions\Metadata('previousPhaseRegistrationsImported', ['label' => \MapasCulturais\i::__('Previous phase registrations imported')]);

        $app->registerMetadata($def__is_opportunity_phase, 'MapasCulturais\Entities\Opportunity');
        $app->registerMetadata($def__previous_phase_imported, 'MapasCulturais\Entities\Opportunity');
        
        $def__prev = new Definitions\Metadata('previousPhaseRegistrationId', ['label' => \MapasCulturais\i::__('Previous phase registration id')]);
        $def__next = new Definitions\Metadata('nextPhaseRegistrationId', ['label' => \MapasCulturais\i::__('Next phase registration id')]);

        $app->registerMetadata($def__prev, 'MapasCulturais\Entities\Registration');
        $app->registerMetadata($def__next, 'MapasCulturais\Entities\Registration');
    }
    
}