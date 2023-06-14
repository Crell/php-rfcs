<?php

declare(strict_types=1);

class Book {}

class Missing {}

class Restricted {}

class Invalid {}

class BookRepository
{
    private array $books = [];

    function load(int $id): Book raises Invalid|Restricted|Missing
    {
        if ($id === 0) {
            raise new Invalid;
        }
        if (! isset($this->books[$id])) {
            raise new Missing;
        }
        if (!permission_check('read')) {
            raise new Restricted;
        }

        return $this->books[$id];
    }
}

function controller(int $id)
{
    $repo = new BookRepository();

    $book = $rep->load($id)
        catch (Missing $e) {
            log('Book not found');
        }
        catch (Restricted $e) {
            log('No permission');
        }
        catch (Invalid $e) {
            log('No permission');
        }

    $book = $rep->load($id)
        catch (Missing $e) log('Book not found')
        catch (Restricted $e) log('No permission')
        catch (Invalid $e) log('No permission')
    ;

}


