<?php

namespace TomAtom\JobQueueBundle\Form;

use Spiriit\Bundle\FormFilterBundle\Filter\Form\Type\ChoiceFilterType;
use Spiriit\Bundle\FormFilterBundle\Filter\Form\Type\DateTimeRangeFilterType;
use Spiriit\Bundle\FormFilterBundle\Filter\Form\Type\TextFilterType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use TomAtom\JobQueueBundle\Entity\Job;

class JobFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('command', TextFilterType::class, [
                'label' => 'command.select.label',
                'required' => false
            ])
            ->add('status', ChoiceFilterType::class, [
                'label' => 'job.state',
                'choices' => Job::STATUSES,
                'required' => false
            ])
            ->add('type', ChoiceFilterType::class, [
                'label' => 'job.type',
                'choices' => Job::TYPES,
                'required' => false
            ])
            ->add('createdAt', DateTimeRangeFilterType::class, [
                'label' => 'job.created_from_to',
                'left_datetime_options' => ['widget' => 'single_text', 'required' => false, 'label' => false],
                'right_datetime_options' => ['widget' => 'single_text', 'required' => false, 'label' => false],
                'required' => false,
                'empty_data' => null
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'job.list.filter'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'validation_groups' => ['filtering']
        ]);
    }
}
