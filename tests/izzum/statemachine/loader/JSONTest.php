<?php
namespace izzum\statemachine\loader;
use izzum\statemachine\persistence\Memory;
use izzum\statemachine\Transition;
use izzum\statemachine\State;
use izzum\statemachine\StateMachine;
use izzum\statemachine\Context;
use izzum\statemachine\Identifier;
use izzum\statemachine\Entity;
use izzum\statemachine\Exception;
use izzum\statemachine\loader\Loader;
use izzum\statemachine\loader\LoaderArray;
use izzum\statemachine\utils\Utils;

/**
 * @group statemachine
 * @group loader
 * @group json
 * 
 * @author rolf
 *        
 */
class JSONTest extends \PHPUnit_Framework_TestCase {

    /**
     * @test
     */
    public function shouldLoadTransitionsFromFile()
    {
        $machine = new StateMachine(new Context(new Identifier('json-test', 'test-machine')));
        $this->assertCount(0, $machine->getTransitions());
        $loader = JSON::createFromFile(__DIR__ . '/../../../../assets/json/example.json');
        $count = $loader->load($machine);
        $this->assertCount(2, $machine->getTransitions());
        $this->assertEquals(2, $count);
    }
    
    /**
     * @test
     */
    public function shouldBehave()
    {
        $machine = new StateMachine(new Context(new Identifier('json-test', 'test-machine')));
        $loader = JSON::createFromFile(__DIR__ . '/../../../../assets/json/example.json');
        $count = $loader->load($machine);
        $this->assertContains('bdone', $loader->getJSON());
        $this->assertContains('json-schema', $loader->getJSONSchema());
        $this->assertContains('JSON', $loader->toString());
    }
    
    /**
     * @test
     */
    public function shouldThrowExceptionForNonExistentFileLoading()
    {
        $machine = new StateMachine(new Context(new Identifier('json-test', 'json-machine')));
        try {
            $loader = JSON::createFromFile(__DIR__ . '/bogus.json');
            $this->fail('should not come here');
        }catch(Exception $e) {
            $this->assertEquals(Exception::BAD_LOADERDATA, $e->getCode());
            $this->assertContains('bogus', $e->getMessage());
            $this->assertContains('does not exist', $e->getMessage());
        }
    }
    
    /**
     * @test
     */
    public function shouldThrowExceptionForBadJsonData()
    {
        $machine = new StateMachine(new Context(new Identifier('json-test', 'json-machine')));
        $loader = JSON::createFromFile(__DIR__ . '/fixture_bad_json.json');
        try {
            $loader->load($machine);
            $this->fail('should not come here');
        }catch(Exception $e) {
            $this->assertEquals(Exception::BAD_LOADERDATA, $e->getCode());
            $this->assertContains('decode', $e->getMessage());
        }
    }
    
    /**
     * @test
     */
    public function shouldThrowExceptionForNoMachineData()
    {
        $machine = new StateMachine(new Context(new Identifier('json-test', 'json-machine')));
        $loader = JSON::createFromFile(__DIR__ . '/fixture_no_machines.json');
        try {
            $loader->load($machine);
            $this->fail('should not come here');
        }catch(Exception $e) {
            $this->assertEquals(Exception::BAD_LOADERDATA, $e->getCode());
            $this->assertContains('no machine data', $e->getMessage());
        }
    }
    
    
    /**
     * @test
     */
    public function shouldLoadTransitionsFromJSONString()
    {
        $machine = new StateMachine(new Context(new Identifier('json-test', 'json-machine')));
        $this->assertCount(0, $machine->getTransitions());
        $json = $this->getJSON();
        $loader = new JSON($json);
        $this->assertEquals($this->getJSON(), $loader->getJSON());
        $count = $loader->load($machine);
        $this->assertCount(2, $machine->getTransitions());
        $this->assertEquals(2, $count);
        $tbd = $machine->getTransition('b_to_done');
        $b = $tbd->getStateFrom();
        $d = $tbd->getStateTo();
        $tab = $machine->getTransition('a_to_b');
        $a = $tab->getStateFrom();
        $this->assertEquals($b, $tab->getStateTo());
        $this->assertSame($b, $tab->getStateTo());
        $this->assertTrue($a->isInitial());
        $this->assertTrue($b->isNormal());
        $this->assertTrue($d->isFinal());
    }
    
    protected function getJSON()
    {
        //heredoc syntax
        $json = '{
  "machines": [
    {
      "name": "json-machine",
      "factory": "fully\\\\qualified\\\\factory-for-test-machine",
      "description": "my test-machine description",
      "states": [
        {
          "name": "a",
          "type": "initial",
          "entry_command": "",
          "exit_command": null,
          "entry_callable": null,
          "exit_callable": null,
          "description": "state a description"
        },
        {
          "name": "b",
          "type": "normal",
          "entry_command": "izzum\\\\command\\\\Null",
          "exit_command": "izzum\\\\command\\\\Null",
          "entry_callable": "Static::method",
          "exit_callable": "Static::method",
          "description": "state b description"
        },
        {
          "name": "done",
          "type": "final",
          "entry_command": "izzum\\\\command\\\\Null",
          "exit_command": "izzum\\\\command\\\\Null",
          "entry_callable": "Static::method",
          "exit_callable": null,
          "description": "state done description"
        }
      ],
      "transitions": [
        {
          "state_from": "a",
          "state_to": "b",
          "rule": "izzum\\\\rules\\\\True",
          "command": "izzum\\\\command\\\\Null",
          "guard_callable": "Static::guard",
          "transition_callable": "Static::method",
          "event": "ab",
          "description": "my description for a_to_b"
        },
        {
          "state_from": "b",
          "state_to": "done",
          "rule": null,
          "command": null,
          "guard_callable": null,
          "transition_callable": null,
          "event": "bdone",
          "description": "my description for b_to_done"
        }
      ]
    }
  ]
}';
        return $json;
    }
    
}