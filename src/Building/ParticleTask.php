<?php

namespace Building;

use pocketmine\scheduler\PluginTask;

class ParticleTask extends PluginTask{

    /** @var Main */
    private $plugin;

    public function __construct(Main $plugin){
        parent::__construct($plugin);
        $this->plugin = $plugin;
    }

    public function onRun($currentTick){
        if(!$this->plugin->isEnabled()){
            return;
        }
        $this->plugin->updateParticles();
    }
}