<?php

namespace Parziphal\Parse\Test;

use Parse\ParseObject;
use Parse\ParseUser;
use PHPUnit\Framework\TestCase;
use Parziphal\Parse\ObjectModel;
use Parziphal\Parse\Test\Models\Post;
use Parziphal\Parse\Test\Models\User;
use Parziphal\Parse\Test\Models\Category;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ModelTest extends TestCase
{
    public function testPersistance()
    {
        $data = [
            'n'   => 1,
            'b'   => true,
            'arr' => [1, 2, 3]
        ];

        $post = new Post($data);

        $this->assertSame($data['n'], $post->n);
        $this->assertNull($post->id);

        $post->save();

        $this->assertNotNull($post->id);

        $stored = Post::findOrFail($post->id);

        $this->assertSame($stored->id, $post->id);

        $post->add('arr', 4);
        $post->update(['n' => 2]);

        $post = Post::findOrFail($post->id);

        $this->assertSame(2, $post->n);
        $this->assertSame(4, count($post->arr));

        $post->destroy();

        $destroyed = false;

        try {
            Post::findOrFail($post->id);
        } catch (ModelNotFoundException $e) {
            $destroyed = true;
        }

        $this->assertSame(true, $destroyed);
    }

    public function testBelongsToMany()
    {
        $laravelCategory = Category::create(['name' => 'Laravel']);
        $parseCategory   = Category::create(['name' => 'Parse']);

        $post = Post::create(['title' => 'New post']);

        $post->categories()->save([
            $laravelCategory,
            $parseCategory
        ]);

        $post = Post::with('categories')->findOrFail($post->id);

        $this->assertSame(2, $post->categories->count());
        $this->assertSame($laravelCategory->id, $post->categories->first()->id);
        $this->assertSame($parseCategory->id, $post->categories[1]->id);
    }

    public function testHasManyArray()
    {
        $category = Category::create(['name' => 'Programming']);

        $postA = Post::create(['title' => 'Timeless post']);
        $postA->categories()->save($category);

        $postB = Post::create(['title' => 'Pressing buttons']);
        $postB->categories()->save($category);

        $postC = Post::create(['title' => 'Post C']);

        $category->posts()->save($postC);
        $category->posts()->create(['title' => 'Some new test']);

        $category = Category::with('posts')->findOrFail($category->id);

        $this->assertSame(4, $category->posts->count());
        $this->assertSame($postA->id, $category->posts[0]->id);
        $this->assertSame($postB->id, $category->posts[1]->id);
        $this->assertSame($postC->id, $category->posts[2]->id);
    }

    public function testBelongsToAndHasMany()
    {
        $user = User::create(['name' => 'admin']);

        $post = Post::create([
            'user'  => $user,
            'title' => 'Admin post'
        ]);

        $post = Post::with('user')->findOrFail($post->id);

        $this->assertSame($user->id, $post->user->id);
        // User Has many users
        $this->assertSame($post->id, $user->posts[0]->id);
    }

    public function testHasMany()
    {
        $user = User::create(['name' => 'Has Many']);

        $postData = [
            'title' => 'Has Many Test'
        ];

        $user->posts()->create($postData);

        $user = User::findOrFail($user->id);

        $this->assertSame(1, $user->posts->count());

        $post = new Post();
        $post->user = $user;
        $post->title = 'Yes';
        $post->save();

        $user = User::findOrFail($user->id);

        $this->assertSame(2, $user->posts->count());
    }

    public function testPagination()
    {
        // Clean up any existing posts if necessary - depends on test environment

        // Create 35 posts
        $initialTotal = Post::query()->count();

        for ($i = 1; $i <= 35; $i++) {
            Post::create(['title' => 'Post ' . $i]);
        }

        // Paginate with 10 per page
        $p = Post::query()->paginate(10);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $p);
        $this->assertSame(10, $p->perPage());
        $this->assertSame($initialTotal + 35, $p->total());
        $this->assertSame((int) ceil(($initialTotal + 35) / 10), $p->lastPage());
        $this->assertCount(10, $p->items());
    }

    public function testSimplePagination()
    {
        $initialTotal = Post::query()->count();

        // Create 25 posts
        for ($i = 1; $i <= 25; $i++) {
            Post::create(['title' => 'Simple Post ' . $i]);
        }

        // Page 1
        $p1 = Post::query()->simplePaginate(10, null, 'page', 1);

        $this->assertInstanceOf(\Illuminate\Pagination\Paginator::class, $p1);
        $this->assertSame(10, $p1->perPage());
        $this->assertCount(10, $p1->items());
        $this->assertTrue(method_exists($p1, 'hasMorePages'));

        // Page 3
        $p3 = Post::query()->simplePaginate(10, null, 'page', 3);

        $this->assertCount(10, $p3->items());
    }

    public function testWhenMethod()
    {
        // ensure some posts exist
        for ($i = 1; $i <= 5; $i++) {
            Post::create(['title' => 'When Post ' . $i]);
        }

        $baseQuery = Post::query();

        // apply when true - filter by title containing 'When Post'
        $q1 = $baseQuery->when(true, function ($q) {
            $q->where('title', 'When Post 1');
        });

        $result1 = $q1->get();

        $this->assertIsIterable($result1);

        // apply when false - default closure should run
        $q2 = Post::query()->when(false, function ($q) {
            $q->where('title', 'Should Not Match');
        }, function ($q) {
            // default - do nothing
        });

        $result2 = $q2->get();

        $this->assertIsIterable($result2);
    }
}
