<?php
/**
 * A model class for the RedBean object Page
 *
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! This is a Framework system class - do not edit !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 *
 * @author Lindsay Marshall <lindsay.marshall@ncl.ac.uk>
 * @copyright 2017-2022 Newcastle University
 * @package Framework\Model
 */
    namespace Framework\Model;

    use \Config\Framework as FW;
    use \Framework\Dispatch;
    use \Framework\Exception\BadValue;
    use \Framework\Support\FWPageHelper as Helper;
    use \Support\Context;
/**
 * A class implementing a RedBean model for Page beans
 * @psalm-suppress UnusedClass
 * @phpcsSuppress NunoMaduro.PhpInsights.Domain.Insights.CyclomaticComplexityIsHigh
 */
    final class FWPage extends \RedBeanPHP\SimpleModel
    {
/**
 * @var string   The type of the bean that stores roles for this page
 * @phpcsSuppress SlevomatCodingStandard.Classes.UnusedPrivateElements
 */
        private string $roletype = FW::PAGEROLE;
/**
 * @var array<array<bool>>   Key is name of field and the array contains flags for checkboxes
 * @phpcsSuppress SlevomatCodingStandard.Classes.UnusedPrivateElements
 */
        private static array $editfields = [
            'name'          => [TRUE, FALSE],
            'kind'          => [TRUE, FALSE],
            'source'        => [TRUE, FALSE],
            'active'        => [TRUE, TRUE],
            'mobileonly'    => [TRUE, TRUE],
            'needlogin'     => [TRUE, TRUE],
            'needajax'      => [TRUE, TRUE],
            'needfwutils'   => [TRUE, TRUE],
            'needfwdom'     => [TRUE, TRUE],
            'needvalidate'  => [TRUE, TRUE],
            'neededitable'  => [TRUE, TRUE],
        ];

        use \Framework\Support\HandleRole;
        use \Framework\Support\MakeGuard;
        use \ModelExtend\FWEdit;
/**
 * Function called when a page bean is updated - do error checking in here
 *
 * @throws \Framework\Exception\BadValue
 */
        public function update() : void
        {
            $this->bean->name = \strtolower($this->bean->name);
            if (!\preg_match('/^[a-z][a-z0-9]*/', $this->bean->name))
            {
                throw new BadValue('Invalid page name');
            }
            \Framework\Dispatch::check($this->bean->kind, $this->bean->source);
        }
/**
 * Check user can access the page - does not return if they cannot
 *
 * @psalm-suppress PossiblyNullReference - we know we have a user when we call context->user
 */
        public function check(Context $context) : void
        {
            if ($this->bean->needlogin)
            {
                if (!$context->hasuser())
                { // not logged in
                    $context->divert('/login/?goto='.\urlencode($context->local()->debase($_SERVER['REQUEST_URI'])), TRUE, 'You must login');
                    /* NOT REACHED */
                }
                if (\R::count(FW::PAGEROLE, FW::PAGE.'_id=?', [$this->bean->getID()]) > 0)
                { // there are roles to check
                    $match = \R::getCell('select count(p.id) = count(r.id) and count(p.id) != 0 from '.FW::USER.
                        ' as u inner join '.FW::ROLE.' as r on u.id = r.'.FW::USER.'_id inner join (select * from '.
                        FW::PAGEROLE.' where '.FW::PAGE.'_id=?) as p on p.'.FW::ROLENAME.'_id = r.'.FW::ROLENAME.
                        '_id and p.'.FW::ROLECONTEXT.'_id = r.'.FW::ROLECONTEXT.'_id where u.id=?',
                        [$this->bean->getID(), $context->user()->getID()]);
                    if (!$match ||                                          // User does not have all the required roles
                        ($this->bean->mobileonly && !$context->hasToken())) // not mobile and logged in
                    {
                        \Framework\Dispatch::basicSetup($context, 'error');
                        $context->web()->sendString($context->local()->getRender('@error/403.twig'), \Framework\Web\Web::HTMLMIME, \Framework\Web\StatusCodes::HTTP_FORBIDDEN);
                        exit;
                    }
                }
            }
        }
/**
 * Add a Page
 *
 * This will be called from ajax.php
 *
 * @param Context   $context    The context object for the site
 */
        public static function add(Context $context) : \RedBeanPHP\OODBBean
        {
            $fdt = $context->formdata('post');
            $page = \R::dispense(FW::PAGE);
            foreach (['name', 'kind', 'source'] as $fld)
            { // mandatory
                $page->{$fld} = $fdt->mustFetch($fld);
            }
            foreach (['active', 'needlogin', 'mobileonly', 'needajax', 'needfwutils', 'needfwdom', 'needvalidate', 'neededitable'] as $fld)
            { // optional flags
                $page->{$fld} = $fdt->fetch($fld, 0);
            }
            try
            {
                \R::store($page);
                foreach ($fdt->fetchArray('context') as $ix => $cid)
                { // context, role, start, end, otherinfo
                    if ($cid !== '')
                    {
                        $page->addRoleByBean(
                            $fdt->mustFetchBean(['context', $ix], FW::ROLECONTEXT),  // the context id
                            $fdt->mustFetchBean(['role', $ix], FW::ROLENAME),        // the rolename id
                            $fdt->mustFetch(['otherinfo', $ix]),
                            $fdt->mustFetch(['start', $ix]),
                            $fdt->mustFetch(['end', $ix])
                        );
                    }
                }
                switch ($page->kind)
                {
                case Dispatch::OBJECT:
                    Helper::doObject($context, $page);
                    break;
                case Dispatch::TEMPLATE:
                    Helper::doTemplate($context, $page);
                    break;
                case Dispatch::REDIRECT:
                case Dispatch::REHOME:
                case Dispatch::XREDIRECT:
                case Dispatch::XREHOME:
/** @todo check that the values passed in make sense */
                    break;
                }
                return $page;
            }
            catch (\Throwable $e)
            { // clean up the page we made above. This will cascade delete any pageroles that might have been created
                \R::trash($page);
                throw $e; // throw it up to the handlers above
            }
        }
/**
 * Setup for an edit - nothing to do for pages at the moment.
 *
 * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter
 */
        public function startEdit(Context $context, array $rest) : void // @phan-suppress-current-line PhanUnusedPublicFinalMethodParameter Inherited function spec
        {
        }
/**
 * Handle an edit form for this page
 *
 * @todo Fix things so that developers do not have to have admin. There will be things some can't then do but there can be a special account.
 *
 * @return array [TRUE if error, [error messages]]
 */
        public function edit(Context $context) : array
        {
            $emess = $this->dofields($context->formdata('post'));

            $this->editroles($context);
            $admin = $this->hasrole(FW::FWCONTEXT, FW::ADMINROLE);
            $devel = $this->hasrole(FW::FWCONTEXT, FW::DEVELROLE);
            if (\is_object($devel) && !\is_object($admin))
            { // if we need developer then we also need admin
                $admin = $this->addrole(FW::FWCONTEXT, FW::ADMINROLE, '-', $devel->start, $devel->end);
            }
            if (\is_object($admin) && !$this->bean->needlogin)
            { // if we need admin then we also need login!
                $this->bean->needlogin = 1;
                \R::store($this->bean);
            }
            return [!empty($emess), $emess];
        }
    }
?>
