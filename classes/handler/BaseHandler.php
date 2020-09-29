<?php

namespace SRAG\PegasusHelper\handler;

/**
 * Class BaseHandler
 *
 * The base handler provides the implementation of a chain element which
 * is implemented by the concrete handlers.
 *
 * The motivation behind this approach is to eliminate the growing
 * switch statements in the plugin.
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 *
 * @see chain of responsability
 */
abstract class BaseHandler implements ChainRequestHandler
{

    /**
     * @var BaseHandler $next
     */
    private $next = null;


    /**
     * Called by the implementation of the handle method.
     * MUST only be called if the concrete handler is not responsible to
     * handle the request.
     *
     * @return  void
     */
    final protected function next()
    {
        if (!is_null($this->next)) {
            $this->next->handle();
        }
    }


    /**
     * Add a new chain link to the end of the chain.
     *
     * @param ChainRequestHandler $handler  The chain element which should be added to the chain end.
     * @return  void
     */
    final public function add(ChainRequestHandler $handler)
    {
        if (is_null($this->next)) {
            $this->next = $handler;
        } else {
            $this->next->add($handler);
        }
    }


    /**
     * The request handling logic of the chain link.
     * This logic must call the next() unless it is responsible to handle
     * the request.
     *
     * @return void
     */
    abstract public function handle();
}
